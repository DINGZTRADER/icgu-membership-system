<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApplicationDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StaffMembershipDocumentController extends Controller
{
    public function download(ApplicationDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk($document->storage_disk)->exists($document->object_path), 404);

        return Storage::disk($document->storage_disk)->download(
            $document->object_path,
            $document->original_name,
            ['Content-Type' => $document->mime_type],
        );
    }
}
