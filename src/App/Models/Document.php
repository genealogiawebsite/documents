<?php

namespace LaravelEnso\Documents\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LaravelEnso\Documents\App\Contracts\Ocrable;
use LaravelEnso\Documents\App\Jobs\Ocr as Job;
use LaravelEnso\Files\App\Contracts\Attachable;
use LaravelEnso\Files\App\Contracts\AuthorizesFileAccess;
use LaravelEnso\Files\App\Exceptions\File;
use LaravelEnso\Files\App\Traits\FilePolicies;
use LaravelEnso\Files\App\Traits\HasFile;
use LaravelEnso\Helpers\App\Traits\CascadesMorphMap;
use LaravelEnso\Helpers\App\Traits\UpdatesOnTouch;

class Document extends Model implements Attachable, AuthorizesFileAccess
{
    use CascadesMorphMap, FilePolicies, HasFile, UpdatesOnTouch;

    protected $fillable = ['documentable_type', 'documentable_id', 'text'];

    protected $touches = ['documentable'];

    protected $folder = 'files';

    protected $optimizeImages = true;

    public function documentable()
    {
        return $this->morphTo();
    }

    public function store(array $request, array $files)
    {
        $documents = new Collection();

        $class = Relation::getMorphedModel($request['documentable_type'])
            ?? $request['documentable_type'];

        $documentable = $class::query()->find($request['documentable_id']);

        $this->validateExisting($files, $documentable);

        DB::transaction(fn () => (new Collection($files))
            ->each(fn ($file) => $documents->push($this->storeFile($documentable, $file))));

        return $documents;
    }

    public function scopeFor($query, array $params)
    {
        $query->whereDocumentableId($params['documentable_id'])
            ->whereDocumentableType($params['documentable_type']);
    }

    public function scopeOrdered($query)
    {
        $query->orderByDesc('created_at');
    }

    public function scopeFilter($query, $search)
    {
        $query->when($search, fn ($query) => $query
            ->where(fn ($query) => $query
                ->whereHas('file', fn ($file) => $file
                    ->where('original_name', 'LIKE', '%'.$search.'%')
                )->orWhere('text', 'LIKE', '%'.$search.'%')
            ));
    }

    public function getLoggableMorph()
    {
        return config('enso.documents.loggableMorph');
    }

    public function resizeImages(): array
    {
        return [
            'width' => config('enso.documents.imageWidth'),
            'height' => config('enso.documents.imageHeight'),
        ];
    }

    private function ocr($document)
    {
        if ($this->ocrable($document)) {
            Job::dispatch($document);
        }

        return $this;
    }

    private function ocrable($document)
    {
        return $document->documentable instanceof Ocrable
            && $document->file->mime_type === 'application/pdf';
    }

    private function validateExisting(array $files, $documentable): void
    {
        $existing = $documentable->load('documents.file')
            ->documents->map(fn ($document) => $document->file->original_name);

        $conflictingFiles = (new Collection($files))
            ->map(fn ($file) => $file->getClientOriginalName())
            ->intersect($existing);

        if ($conflictingFiles->isNotEmpty()) {
            throw File::duplicates($conflictingFiles->implode(', '));
        }
    }

    private function storeFile($documentable, $file)
    {
        $document = $documentable->documents()->create();
        $document->upload($file);
        $this->ocr($document);

        return $document;
    }
}
