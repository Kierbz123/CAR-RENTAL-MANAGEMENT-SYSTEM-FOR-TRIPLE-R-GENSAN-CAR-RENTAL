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
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new RuntimeException('Choose a valid JPEG, PNG, or WebP image.');
        $size=(int)($file['size']??0); if ($size<1 || $size>self::MAX_BYTES) throw new RuntimeException('Vehicle photos must be no larger than 8 MB.');
        $finfo=new \finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file((string)$file['tmp_name']);
        if (!is_string($mime) || !isset(self::TYPES[$mime]) || @getimagesize((string)$file['tmp_name'])===false) throw new RuntimeException('Only JPEG, PNG, and WebP images are accepted.');
        $base=Config::get('STORAGE_PATH','storage')??'storage'; $root=str_starts_with($base,DIRECTORY_SEPARATOR)?$base:APP_ROOT . DIRECTORY_SEPARATOR . $base;
        $dir=$root . DIRECTORY_SEPARATOR . 'vehicles' . DIRECTORY_SEPARATOR . $vehicleId;
        if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('Private photo storage is unavailable.');
        $name=bin2hex(random_bytes(24)) . '.' . self::TYPES[$mime]; $path=$dir . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file((string)$file['tmp_name'],$path)) throw new RuntimeException('The photo could not be stored.');
        try {
            $order=$this->db->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM vehicle_photos WHERE vehicle_id=:id'); $order->execute(['id'=>$vehicleId]);
            $stmt=$this->db->prepare('INSERT INTO vehicle_photos (vehicle_id,storage_path,original_filename,mime,size_bytes,sort_order,uploaded_by) VALUES (:vehicle,:path,:original,:mime,:size,:sort,:actor)');
            $stmt->execute(['vehicle'=>$vehicleId,'path'=>'vehicles/' . $vehicleId . '/' . $name,'original'=>substr(basename((string)($file['name']??'photo')),0,255),'mime'=>$mime,'size'=>$size,'sort'=>(int)$order->fetchColumn(),'actor'=>$actor]);
        } catch (\Throwable $e) { @unlink($path); throw $e; }
    }

    public function stream(int $photoId): array
    {
        $stmt=$this->db->prepare('SELECT storage_path,mime,original_filename FROM vehicle_photos WHERE photo_id=:id'); $stmt->execute(['id'=>$photoId]); $row=$stmt->fetch();
        if (!$row) throw new RuntimeException('Photo not found.');
        $base=Config::get('STORAGE_PATH','storage')??'storage'; $root=str_starts_with($base,DIRECTORY_SEPARATOR)?$base:APP_ROOT . DIRECTORY_SEPARATOR . $base;
        $path=$root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'],DIRECTORY_SEPARATOR,(string)$row['storage_path']);
        $realRoot=realpath($root); $realPath=realpath($path);
        if (!$realRoot || !$realPath || !str_starts_with($realPath,$realRoot . DIRECTORY_SEPARATOR) || !is_file($realPath)) throw new RuntimeException('Photo file is unavailable.');
        return ['body'=>(string)file_get_contents($realPath),'mime'=>(string)$row['mime']];
    }
}
