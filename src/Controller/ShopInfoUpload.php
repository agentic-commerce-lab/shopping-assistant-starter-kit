<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * One upload, validated once out of the request.
 *
 * Split from {@see ShopInfoDocumentController} (cyclomatic-complexity) following
 * {@see ChatRequest} and {@see ProbeRequest}, and the split earns its keep: a multipart request is
 * untyped, and pushing the narrowing here leaves the controller with a readable sequence of refusals.
 *
 * `error` carries the merchant-facing reason and is the only thing the caller has to check. There is
 * no valid-but-error state: either `error` is null and the bytes are present, or it is not.
 */
final readonly class ShopInfoUpload
{
    /**
     * Extensions this accepts, which is deliberately the same list spec R10 admits and no wider.
     *
     * Refusing here rather than letting extraction fail means the merchant is told "xlsx cannot be
     * indexed" before a document row exists, instead of finding a failed row afterwards.
     *
     * @var list<string>
     */
    public const ACCEPTED = ['pdf', 'txt', 'md', 'markdown', 'html', 'htm', 'docx'];

    /**
     * The largest file this accepts, in bytes.
     *
     * Far more than any terms-and-conditions document and far less than something that would hold a
     * request open embedding hundreds of chunks. A merchant who reaches it has uploaded a scanned
     * catalogue, and the message says so rather than the request timing out.
     */
    public const MAX_BYTES = 8 * 1024 * 1024;

    private function __construct(
        public string $name,
        public string $bytes,
        public string $salesChannelId,
        public ?string $error = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $salesChannelId = (string) $request->request->get('salesChannelId', '');
        $file = $request->files->get('file');

        if ($salesChannelId === '' || !$file instanceof UploadedFile) {
            return self::refused('A file and a sales channel are required.');
        }

        $name = basename($file->getClientOriginalName());
        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        if (!\in_array($extension, self::ACCEPTED, true)) {
            return self::refused(\sprintf(
                'Files of type ".%s" cannot be indexed. Accepted: %s.',
                $extension,
                implode(', ', self::ACCEPTED),
            ));
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return self::refused(\sprintf(
                '"%s" is larger than %d MB.',
                $name,
                (int) ((self::MAX_BYTES / 1024) / 1024),
            ));
        }

        $bytes = file_get_contents($file->getPathname());

        if ($bytes === false || $bytes === '') {
            return self::refused(\sprintf('"%s" is empty or could not be read.', $name));
        }

        return new self($name, $bytes, $salesChannelId);
    }

    private static function refused(string $error): self
    {
        return new self('', '', '', $error);
    }
}
