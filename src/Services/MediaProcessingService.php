<?php

namespace App\Services;

use Imagick;
use Aws\S3\S3Client;
use GuzzleHttp\Client;

class MediaProcessingService
{
    public function __construct(
        private string $s3_default_region,
        private string $s3_access_key_id,
        private string $s3_secret_access_key
    ) {}
    private function connects3(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region'  => $this->s3_default_region,
            'credentials' => [
                'key'    => $this->s3_access_key_id,
                'secret' => $this->s3_secret_access_key,
            ],
        ]);
    }
    public function webpToMedia(array $data): array
    {
        // Download the WebP file from the URL
        $client = new Client();
        $response = $client->get($data['url'], [
            'headers' => $data['headers'] ?? []
        ]);
        $webpContent = $response->getBody()->getContents();

        if ($this->detectMediaTypeFromContent($webpContent) !== 'webp') {
            return ['error' => 'Unsupported media type'];
        }

        // Determine if the WebP is animated or static
        $isAnimated = $this->isAnimatedWebp($webpContent);

        // Convert the WebP to the appropriate media format
        if ($isAnimated) {
            $mediaContent = $this->convertWebpToMp4($webpContent);
            $mediaExtension = 'mp4';
        } else {
            $mediaContent = $this->convertWebpToJpeg($webpContent);
            $mediaExtension = 'jpg';
        }

        // Generate a unique filename for the media file
        $filename = uniqid() . '.' . $mediaExtension;

        // Save the media file temporarily
        $tempFilePath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempFilePath, $mediaContent);

        // Upload the media file to S3
        $s3Client = $this->connects3();

        $bucketName = getenv('S3_BUCKET_NAME');
        $objectKey = 'converts/' . $filename;
        $s3Client->putObject([
            'Bucket' => $bucketName,
            'Key'    => $objectKey,
            'Body'   => fopen($tempFilePath, 'r'),
        ]);

        // Remove the temporary file
        unlink($tempFilePath);

        // Return the URL of the uploaded media file
        $preSignedUrl = $s3Client->createPresignedRequest(
            $s3Client->getCommand('GetObject', [
                'Bucket' => $bucketName,
                'Key'    => $objectKey,
            ]),
            '+1 hour'
        )->getUri();

        return ['url' => $preSignedUrl];
    }

    public function mediaToWebp(array $data): array
    {
        // Download the media file from the URL
        $client = new Client();

        $response = $client->get($data['url'], [
            'headers' => $data['headers'] ?? []
        ]);
        $mediaContent = $response->getBody()->getContents();

        // Determine the media type (photo or video)
        $mediaType = $this->getMediaType($response->getHeaderLine('Content-Type'));

        if ($mediaType === 'unknown') {
            // If content type is application/octet-stream, try to determine the type from the file content
            $mediaType = $this->detectMediaTypeFromContent($mediaContent);
        }

        if (!in_array($mediaType, ['photo', 'video'])) {
            return ['error' => 'Unsupported media type'];
        }

        // Convert the media to WebP format
        $webpContent = $this->convertToWebp($mediaContent, $mediaType, $data['url']);

        // Generate a unique filename for the WebP file
        $filename = uniqid() . '.webp';

        // Save the WebP file temporarily
        $tempFilePath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempFilePath, $webpContent);

        // Upload the WebP file to S3
        $s3Client = $this->connects3();

        $bucketName = getenv('S3_BUCKET_NAME');
        $objectKey = 'converts/' . $filename;
        $s3Client->putObject([
            'Bucket' => $bucketName,
            'Key' => $objectKey,
            'Body' => fopen($tempFilePath, 'r'),
        ]);

        // Remove the temporary file
        unlink($tempFilePath);

        // Return the URL of the uploaded WebP file
        $preSignedUrl = $s3Client->createPresignedRequest(
            $s3Client->getCommand('GetObject', [
                'Bucket' => $bucketName,
                'Key'    => $objectKey,
            ]),
            '+1 hour'
        )->getUri();

        return ['url' => $preSignedUrl];
    }

    private function isAnimatedWebp($webpContent)
    {
        // Check if the WebP is animated by looking for the "ANIM" chunk
        return strpos($webpContent, 'ANIM') !== false;
    }

    private function convertWebpToJpeg($webpContent)
    {
        // Convert WebP to JPEG using GD library
        $image = imagecreatefromstring($webpContent);
        ob_start();
        imagejpeg($image);
        $jpegContent = ob_get_clean();
        imagedestroy($image);
        return $jpegContent;
    }

    private function convertWebpToMp4($webpContent)
    {
        // Convert animated WebP to MP4 using FFmpeg
        $tempWebpFilePath = sys_get_temp_dir() . '/' . uniqid() . '.webp';
        file_put_contents($tempWebpFilePath, $webpContent);
        $outputFilePath = sys_get_temp_dir() . '/' . uniqid() . '.mp4';

        $imagick = new Imagick();

        // Read the animated WebP file
        $imagick->readImage($tempWebpFilePath);

        // Set the format to MP4
        $imagick->setImageFormat('mp4');

        // Set the video codec and bitrate (adjust as needed)
        $imagick->setOption('video:codec', 'libx264');
        $imagick->setOption('video:bitrate', '1000k');

        // Set the frame rate (adjust as needed)
        $imagick->setImageDelay(20);
        $imagick->setImageTicksPerSecond(50);

        // Write the MP4 file
        $imagick->writeImages($outputFilePath, true);

        $mp4Content = file_get_contents($outputFilePath);
        unlink($tempWebpFilePath);
        unlink($outputFilePath);

        return $mp4Content;
    }
    private function getMediaType($contentType)
    {
        if ($contentType === 'image/webp') {
            return 'webp';
        } elseif (strpos($contentType, 'image/') === 0) {
            return 'photo';
        } elseif (strpos($contentType, 'video/') === 0) {
            return 'video';
        } elseif ($contentType === 'application/octet-stream') {
            return 'unknown';
        }
        return 'unknown';
    }
    private function detectMediaTypeFromContent($content)
    {
        // Check for WebP signature
        if (substr($content, 0, 4) === "RIFF" && substr($content, 8, 4) === "WEBP") {
            return 'webp';
        }

        // Check for other image signatures
        $imageSignatures = [
            "\xFF\xD8\xFF" => 'photo', // JPEG
            "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A" => 'photo', // PNG
            "GIF" => 'photo', // GIF
        ];

        foreach ($imageSignatures as $signature => $type) {
            if (strpos($content, $signature) === 0) {
                return $type;
            }
        }

        // Check for video signatures
        $videoSignatures = [
            "\x00\x00\x00\x18\x66\x74\x79\x70\x6D\x70\x34\x32" => 'video', // MP4
            "\x1A\x45\xDF\xA3" => 'video', // WebM
        ];

        foreach ($videoSignatures as $signature => $type) {
            if (strpos($content, $signature) === 0) {
                return $type;
            }
        }

        return 'unknown';
    }
    private function convertToWebp($mediaContent, $mediaType, $url)
    {
        if ($mediaType === 'photo') {
            // Convert photo to WebP using GD library
            $image = imagecreatefromstring($mediaContent);
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width === 0 || $height === 0) {
                throw new \Exception('Invalid image dimensions');
            }

            // Create a new 512x512 image with transparent background
            $newImage = imagecreatetruecolor(512, 512);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
            imagefill($newImage, 0, 0, $transparent);

            // Calculate the new dimensions while maintaining the aspect ratio
            $ratio = min(512 / $width, 512 / $height);
            $newWidth = $width * $ratio;
            $newHeight = $height * $ratio;

            // Resize and center the image
            $x = (int)((512 - $newWidth) / 2);
            $y = (int)((512 - $newHeight) / 2);

            imagecopyresampled($newImage, $image, $x, $y, 0, 0, (int)$newWidth, (int)$newHeight, (int)$width, (int)$height);

            ob_start();
            imagewebp($newImage);
            $webpContent = ob_get_clean();
            imagedestroy($image);
            imagedestroy($newImage);
        } elseif ($mediaType === 'video') {
            // Convert video to WebP using FFmpeg
            $tempFilePath = sys_get_temp_dir() . '/' . uniqid() . '.' . pathinfo($url, PATHINFO_EXTENSION);
            file_put_contents($tempFilePath, $mediaContent);
            $outputFilePath = sys_get_temp_dir() . '/' . uniqid() . '.webp';
            $command = "ffmpeg -i {$tempFilePath} -r 10 -ss 00:00:00 -t 00:00:05 -vf 'scale=512:512:force_original_aspect_ratio=decrease,pad=512:512:(ow-iw)/2:(oh-ih)/2:color=black' -vcodec libwebp -quality 20 -loop 0 -preset default -an {$outputFilePath}";
            shell_exec($command);
            $webpContent = file_get_contents($outputFilePath);
            unlink($tempFilePath);
            unlink($outputFilePath);
        } else {
            throw new \Exception('Unsupported media type');
        }

        return $webpContent;
    }
}
