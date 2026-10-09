<?php
declare(strict_types=1);

namespace TripleR\Services;

use RuntimeException;

/**
 * One portrait photo for a customer or a driver, kept in private storage beside the vehicle
 * photos and checked by the same rules (JPEG, PNG or WebP, up to 8 MB).
 *
 * ponytail: the photo is found by its folder (customers/12/profile/), not by a database row,
 * so adding it needed no schema change. Move it into the photos table if who uploaded a
 * portrait, and when, ever has to be recorded.
 */
final class ProfilePhotoService
{
    private const OWNERS = ['customers', 'drivers'];
    private const MIMES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(private readonly VehiclePhotoService $files)
    {
    }

    public function has(string $owner, int $id): bool
    {
        return $this->stored($owner, $id) !== [];
    }

    /** @return array{body:string,mime:string}|null */
    public function read(string $owner, int $id): ?array
    {
        $path = $this->stored($owner, $id)[0] ?? null;
        if ($path === null) {
            return null;
        }
        return ['body' => $this->files->readEvidence($path), 'mime' => self::MIMES[pathinfo($path, PATHINFO_EXTENSION)]];
    }

    /** Saves the uploaded photo, then drops the one it replaces. A rejected upload leaves the old photo alone. */
    public function replace(string $owner, int $id, array $file): void
    {
        $previous = $this->stored($owner, $id);
        $this->files->storeEvidence($file, $this->folder($owner, $id));
        array_map($this->files->removeEvidence(...), $previous);
    }

    public function remove(string $owner, int $id): void
    {
        array_map($this->files->removeEvidence(...), $this->stored($owner, $id));
    }

    /** @return list<string> the files in the record's folder; one at most once an upload has finished */
    private function stored(string $owner, int $id): array
    {
        return $this->files->listEvidence($this->folder($owner, $id));
    }

    private function folder(string $owner, int $id): string
    {
        if (!in_array($owner, self::OWNERS, true) || $id < 1) {
            throw new RuntimeException('Photo not found.');
        }
        return $owner . '/' . $id . '/profile';
    }
}
