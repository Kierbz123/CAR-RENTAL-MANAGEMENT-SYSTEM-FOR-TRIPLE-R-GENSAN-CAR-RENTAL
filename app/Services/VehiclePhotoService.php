<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;
use RuntimeException;
use TripleR\Config;

final class VehiclePhotoService
{
    private const MAX_BYTES = 8_388_608;
    private const TYPES = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];

    public function __construct(private readonly PDO $db) {}

    public function upload(int $vehicleId, array $file, int $actor): void
    {
        $stored = $this->storeEvidence($file, 'vehicles/' . $vehicleId);
        try {
            $order=$this->db->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM photos WHERE vehicle_id=:id'); $order->execute(['id'=>$vehicleId]);
            $stmt=$this->db->prepare('INSERT INTO photos (vehicle_id,storage_path,original_filename,mime,size_bytes,sort_order,uploaded_by) VALUES (:vehicle,:path,:original,:mime,:size,:sort,:actor)');
            $stmt->execute(['vehicle'=>$vehicleId,'path'=>$stored['storage_path'],'original'=>$stored['original_filename'],'mime'=>$stored['mime'],'size'=>$stored['size_bytes'],'sort'=>(int)$order->fetchColumn(),'actor'=>$actor]);
        } catch (\Throwable $e) { $this->removeEvidence($stored['storage_path']); throw $e; }
    }

    /** Puts one photo first. The first photo is the cover: the one the vehicle list shows. */
    public function makeCover(int $photoId, int $vehicleId): void
    {
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare('SELECT photo_id FROM photos WHERE vehicle_id=:id ORDER BY sort_order, photo_id FOR UPDATE'); $stmt->execute(['id'=>$vehicleId]);
            $ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
            if (!in_array($photoId,$ids,true)) throw new RuntimeException('Photo not found.');
            $place=$this->db->prepare('UPDATE photos SET sort_order=:position WHERE photo_id=:id');
            foreach (array_merge([$photoId],array_diff($ids,[$photoId])) as $position=>$id) $place->execute(['position'=>$position,'id'=>$id]);
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public function stream(int $photoId): array
    {
        // Photos of every kind share one table: this route serves vehicle photos only.
        $stmt=$this->db->prepare('SELECT storage_path,mime,original_filename FROM photos WHERE photo_id=:id AND vehicle_id IS NOT NULL'); $stmt->execute(['id'=>$photoId]); $row=$stmt->fetch();
        if (!$row) throw new RuntimeException('Photo not found.');
        return ['body'=>$this->readEvidence((string)$row['storage_path']),'mime'=>(string)$row['mime']];
    }

    /** Shared private evidence pipeline for vehicle and damage photographs. */
    public function storeEvidence(array $file, string $relativeDirectory): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new RuntimeException('Choose a valid JPEG, PNG, or WebP image.');
        $size=(int)($file['size']??0); if ($size<1 || $size>self::MAX_BYTES) throw new RuntimeException('Photos must be no larger than 8 MB.');
        $tmp=(string)$file['tmp_name']; $finfo=new \finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($tmp);
        if (!is_string($mime) || !isset(self::TYPES[$mime]) || @getimagesize($tmp)===false) throw new RuntimeException('Only JPEG, PNG, and WebP images are accepted.');
        $relativeDirectory=trim(str_replace('\\','/',$relativeDirectory),'/');
        if ($relativeDirectory==='' || preg_match('~(^|/)\.\.?(/|$)~',$relativeDirectory)) throw new RuntimeException('Private photo storage path is invalid.');
        $root=$this->storageRoot(); $dir=$root . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$relativeDirectory);
        if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('Private photo storage is unavailable.');
        $relativePath=$relativeDirectory . '/' . bin2hex(random_bytes(24)) . '.' . self::TYPES[$mime];
        $path=$root . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$relativePath);
        if (!move_uploaded_file($tmp,$path)) throw new RuntimeException('The photo could not be stored.');
        return ['storage_path'=>$relativePath,'original_filename'=>substr(basename((string)($file['name']??'photo')),0,255),'mime'=>$mime,'size_bytes'=>$size];
    }

    public function readEvidence(string $relativePath): string
    {
        $relativePath=str_replace(['/', '\\'],DIRECTORY_SEPARATOR,$relativePath);
        if ($relativePath==='' || str_contains($relativePath,'..')) throw new RuntimeException('Photo file is unavailable.');
        $root=$this->storageRoot(); $realRoot=realpath($root); $realPath=realpath($root . DIRECTORY_SEPARATOR . $relativePath);
        if (!$realRoot || !$realPath || !str_starts_with($realPath,$realRoot . DIRECTORY_SEPARATOR) || !is_file($realPath)) throw new RuntimeException('Photo file is unavailable.');
        $body=file_get_contents($realPath); if ($body===false) throw new RuntimeException('Photo file is unavailable.'); return $body;
    }

    /** The image files directly inside one private folder, as paths readEvidence() accepts. */
    public function listEvidence(string $relativeDirectory): array
    {
        $relativeDirectory=trim(str_replace('\\','/',$relativeDirectory),'/');
        if ($relativeDirectory==='' || preg_match('~(^|/)\.\.?(/|$)~',$relativeDirectory)) return [];
        $dir=$this->storageRoot() . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$relativeDirectory);
        if (!is_dir($dir)) return [];
        $names=array_filter(scandir($dir) ?: [], static fn(string $name): bool => preg_match('/^[a-f0-9]+\.(jpg|png|webp)$/',$name)===1);
        return array_values(array_map(static fn(string $name): string => $relativeDirectory . '/' . $name, $names));
    }

    public function removeEvidence(string $relativePath): void
    {
        try { $path=$this->evidencePath($relativePath); if (is_file($path)) @unlink($path); } catch (RuntimeException) {}
    }

    private function evidencePath(string $relativePath): string
    {
        $relativePath=str_replace(['/', '\\'],DIRECTORY_SEPARATOR,$relativePath); if ($relativePath==='' || str_contains($relativePath,'..')) throw new RuntimeException('Photo file is unavailable.');
        return $this->storageRoot() . DIRECTORY_SEPARATOR . $relativePath;
    }

    private function storageRoot(): string
    {
        $base=Config::get('STORAGE_PATH','storage')??'storage'; return str_starts_with($base,DIRECTORY_SEPARATOR)?$base:APP_ROOT . DIRECTORY_SEPARATOR . $base;
    }
}
