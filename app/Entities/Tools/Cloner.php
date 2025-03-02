<?php

namespace BookStack\Entities\Tools;

use BookStack\Activity\Models\Tag;
use BookStack\Entities\Models\Book;
use BookStack\Entities\Models\Bookshelf;
use BookStack\Entities\Models\Chapter;
use BookStack\Entities\Models\Entity;
use BookStack\Entities\Models\HasCoverImage;
use BookStack\Entities\Models\Page;
use BookStack\Entities\Repos\BookRepo;
use BookStack\Entities\Repos\ChapterRepo;
use BookStack\Entities\Repos\PageRepo;
use BookStack\Uploads\Image;
use BookStack\Uploads\ImageService;
use Illuminate\Http\UploadedFile;

class Cloner
{
    public function __construct(
        protected PageRepo $pageRepo,
        protected ChapterRepo $chapterRepo,
        protected BookRepo $bookRepo,
        protected ImageService $imageService,
    ) {
    }

    /**
     * Clone the given page into the given parent using the provided name.
     */
    public function clonePage(Page $original, Entity $parent, string $newName): Page
    {
        $copyPage = $this->pageRepo->getNewDraftPage($parent);
        $pageData = $this->entityToInputData($original);
        $pageData['name'] = $newName;

        return $this->pageRepo->publishDraft($copyPage, $pageData);
    }

    /**
     * Clone the given page into the given parent using the provided name.
     * Clones all child pages.
     */
    public function cloneChapter(Chapter $original, Book $parent, string $newName): Chapter
    {
        $chapterDetails = $this->entityToInputData($original);
        $chapterDetails['name'] = $newName;

        $copyChapter = $this->chapterRepo->create($chapterDetails, $parent);

        if (userCan('page-create', $copyChapter)) {
            /** @var Page $page */
            foreach ($original->getVisiblePages() as $page) {
                $this->clonePage($page, $copyChapter, $page->name);
            }
        }

        return $copyChapter;
    }

    /**
     * Clone the given book.
     * Clones all child chapters & pages.
     */
    public function cloneBook(Book $original, string $newName): Book
    {
        $bookDetails = $this->entityToInputData($original);
        $bookDetails['name'] = $newName;

        // Clone book
        $copyBook = $this->bookRepo->create($bookDetails);

        // Clone contents
        $directChildren = $original->getDirectVisibleChildren();
        foreach ($directChildren as $child) {
            if ($child instanceof Chapter && userCan('chapter-create', $copyBook)) {
                $this->cloneChapter($child, $copyBook, $child->name);
            }

            if ($child instanceof Page && !$child->draft && userCan('page-create', $copyBook)) {
                $this->clonePage($child, $copyBook, $child->name);
            }
        }

        // Clone bookshelf relationships
        /** @var Bookshelf $shelf */
        foreach ($original->shelves as $shelf) {
            if (userCan('bookshelf-update', $shelf)) {
                $shelf->appendBook($copyBook);
            }
        }

        return $copyBook;
    }

    /**
     * Convert an entity to a raw data array of input data.
     *
     * @return array<string, mixed>
     */
    public function entityToInputData(Entity $entity): array
    {
        $inputData = $entity->getAttributes();
        $inputData['tags'] = $this->entityTagsToInputArray($entity);

        // Add a cover to the data if existing on the original entity
        if ($entity instanceof HasCoverImage) {
            $cover = $entity->cover()->first();
            if ($cover) {
                $inputData['image'] = $this->imageToUploadedFile($cover);
            }
        }

        return $inputData;
    }

    /**
     * Copy the permission settings from the source entity to the target entity.
     */
    public function copyEntityPermissions(Entity $sourceEntity, Entity $targetEntity): void
    {
        $permissions = $sourceEntity->permissions()->get(['role_id', 'view', 'create', 'update', 'delete'])->toArray();
        $targetEntity->permissions()->delete();
        $targetEntity->permissions()->createMany($permissions);
        $targetEntity->rebuildPermissions();
    }

    /**
     * Convert an image instance to an UploadedFile instance to mimic
     * a file being uploaded.
     */
    protected function imageToUploadedFile(Image $image): ?UploadedFile
    {
        $imgData = $this->imageService->getImageData($image);
        $tmpImgFilePath = tempnam(sys_get_temp_dir(), 'bs_cover_clone_');
        file_put_contents($tmpImgFilePath, $imgData);

        return new UploadedFile($tmpImgFilePath, basename($image->path));
    }

    /**
     * Convert the tags on the given entity to the raw format
     * that's used for incoming request data.
     */
    protected function entityTagsToInputArray(Entity $entity): array
    {
        $tags = [];

        /** @var Tag $tag */
        foreach ($entity->tags as $tag) {
            $tags[] = ['name' => $tag->name, 'value' => $tag->value];
        }

        return $tags;
    }
}
