<?php

declare(strict_types=1);

namespace Infrastructure\Person;

use Domain\Admissions\Service\AdmissionDocumentScanner;
use Domain\Person\Service\PhotoPolicy;
use Domain\Person\Service\PhotoProcessor;
use Domain\Person\ValueObject\ProfilePhoto;
use Domain\Shared\Exception\ValidationException;
use Imagick;

use function Codefy\Framework\Helpers\trans;

final readonly class ImagickPhotoProcessor implements PhotoProcessor
{
    public function __construct(private AdmissionDocumentScanner $scanner)
    {
    }

    public function normalize(
        #[\SensitiveParameter] string $bytes,
        string $filename,
    ): ProfilePhoto {
        $error = [
            [
                "field" => "photo",
                "message" =>
                    trans(
                        "Choose a single PNG or JPG up to 5 MiB and four million pixels. "
                        . "Image scanning must succeed."
                    ),
            ],
        ];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $info =
        strlen($bytes) <= PhotoPolicy::MAX_IMAGE
        ? @getimagesizefromstring($bytes)
        : false;
        if (
            !class_exists(Imagick::class) ||
            !class_exists(\finfo::class) ||
            !in_array($extension, ["jpg", "png"], true) ||
            !$info ||
            !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) ||
            ($extension === "jpg") !== ($info[2] === IMAGETYPE_JPEG) ||
            min($info[0], $info[1]) < 32 ||
            max($info[0], $info[1]) > 4096 ||
            $info[0] * $info[1] > 4000000
        ) {
            throw new ValidationException($error);
        }
        if (
            new \finfo(FILEINFO_MIME_TYPE)->buffer($bytes) !==
            ($info[2] === IMAGETYPE_JPEG ? "image/jpeg" : "image/png")
        ) {
            throw new ValidationException($error);
        }
        if ($this->scanner->scan($bytes) !== "clean") {
            throw new ValidationException($error);
        }
        $limits = [
            Imagick::RESOURCETYPE_MEMORY => 64 * 1024 * 1024,
            Imagick::RESOURCETYPE_MAP => 0,
            Imagick::RESOURCETYPE_DISK => 0,
            Imagick::RESOURCETYPE_THREAD => 1,
        ];
        $before = [];
        $image = new Imagick();
        try {
            foreach ($limits as $type => $limit) {
                $existing = Imagick::getResourceLimit($type);
                // Some builds return floating-point "unlimited" values outside PHP's exact integer range.
                $before[$type] =
                $existing >= PHP_INT_MAX ? PHP_INT_MAX : (int) $existing;
                Imagick::setResourceLimit($type, min($before[$type], $limit));
            }
            // An explicit raster decoder prevents content-based selection of delegates/vector coders.
            $image->setFormat($info[2] === IMAGETYPE_JPEG ? "jpeg" : "png");
            $image->readImageBlob($bytes);
            if ($image->getNumberImages() !== 1) {
                throw new \RuntimeException();
            }
            $image->autoOrient();
            $image->setImageBackgroundColor("white");
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $image->cropThumbnailImage(512, 512);
            $image->stripImage();
            $image->setImageFormat("jpeg");
            $image->setImageCompressionQuality(88);
            return new ProfilePhoto($image->getImageBlob());
        } catch (\Throwable) {
            throw new ValidationException($error);
        } finally {
            $image->clear();
            foreach ($before as $type => $limit) {
                Imagick::setResourceLimit($type, $limit);
            }
        }
    }
}
