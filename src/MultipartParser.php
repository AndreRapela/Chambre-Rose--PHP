<?php

declare(strict_types=1);

namespace ChambreRose;

final class MultipartParser
{
    /** @return array{fields: array<string, string>, files: array<string, UploadedFile>} */
    public static function parse(string $body, string $contentType): array
    {
        if (!preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', $contentType, $match)) {
            throw new ApiException(400, 'Invalid multipart request.');
        }

        $boundary = $match[1] !== '' ? $match[1] : trim($match[2]);
        if ($boundary === '' || strlen($boundary) > 200) {
            throw new ApiException(400, 'Invalid multipart boundary.');
        }

        $fields = [];
        $files = [];
        foreach (explode('--' . $boundary, $body) as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || $part === '--' || str_starts_with($part, '--\r\n')) {
                continue;
            }

            $part = preg_replace('/\r\n$/', '', $part) ?? $part;
            $separator = strpos($part, "\r\n\r\n");
            if ($separator === false) {
                continue;
            }

            $headerBlock = substr($part, 0, $separator);
            $contents = substr($part, $separator + 4);
            $contents = preg_replace('/\r\n$/', '', $contents) ?? $contents;

            if (!preg_match('/Content-Disposition:\s*form-data;([^\r\n]+)/i', $headerBlock, $disposition)) {
                continue;
            }
            if (!preg_match('/\bname="([^"]+)"/i', $disposition[1], $nameMatch)) {
                continue;
            }

            $name = $nameMatch[1];
            if (preg_match('/\bfilename="([^"]*)"/i', $disposition[1], $fileMatch)) {
                $fileName = self::safeFileName($fileMatch[1]);
                $type = 'application/octet-stream';
                if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $headerBlock, $typeMatch)) {
                    $type = trim($typeMatch[1]);
                }
                $files[$name] = new UploadedFile($fileName, $type, strlen($contents), null, $contents);
            } else {
                $fields[$name] = $contents;
            }
        }

        return ['fields' => $fields, 'files' => $files];
    }

    private static function safeFileName(string $name): string
    {
        $normalized = str_replace('\\', '/', $name);

        return trim(str_replace('"', '', basename($normalized))) ?: 'profile-media';
    }
}
