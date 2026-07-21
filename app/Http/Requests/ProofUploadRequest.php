<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff uploads proof artwork through the app rather than pasting a link from
 * some other service. Deliberately NOT the same request as ArtworkUploadRequest:
 *
 *  - That endpoint is the public, account-free designer upload. It is
 *    image-only because the designer canvas renders what it stores, and it is
 *    capped at 10 MB for buyer artwork. Tightening it to suit proofs would
 *    silently shrink what buyers may upload.
 *  - Proofs are staff-only, are frequently PDF, and are capped at 3 MB.
 */
class ProofUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 3072 KB = 3 MB. mimes covers the extension; the mimetypes rule is
            // the one that inspects actual content, so a .pdf that is really an
            // executable is rejected rather than stored.
            'proof' => [
                'required',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp',
                'mimetypes:application/pdf,image/png,image/jpeg,image/webp',
                'max:3072',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proof.max' => 'The proof must be 3 MB or smaller.',
            'proof.mimes' => 'Proofs must be a PDF, PNG, JPG or WEBP file.',
            'proof.mimetypes' => 'Proofs must be a PDF, PNG, JPG or WEBP file.',
        ];
    }
}
