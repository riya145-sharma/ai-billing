<?php
declare(strict_types=1);

namespace App\Services;

/**
 * What each Azure image model family accepts. Request bodies differ:
 *  - gpt-image-* : size 1024x1024 | 1024x1536 | 1536x1024, quality low|medium|high,
 *                  always returns base64 (sending response_format is an error)
 *  - dall-e-3    : size 1024x1024 | 1792x1024 | 1024x1792, quality standard|hd,
 *                  needs response_format=b64_json to get the bytes back
 * Unknown models are treated like gpt-image (the current Azure family).
 */
final class ImageModels
{
    public static function profile(string $model): array
    {
        $m = strtolower($model);
        if (str_starts_with($m, 'dall-e')) {
            return [
                'family'    => 'dalle',
                'sizes'     => ['1024x1024' => 'Square (1024×1024)', '1792x1024' => 'Landscape (1792×1024)', '1024x1792' => 'Portrait (1024×1792)'],
                'qualities' => ['standard' => 'Standard', 'hd' => 'HD'],
                'default_quality' => 'standard',
            ];
        }
        return [
            'family'    => 'gpt-image',
            'sizes'     => ['1024x1024' => 'Square (1024×1024)', '1536x1024' => 'Landscape (1536×1024)', '1024x1536' => 'Portrait (1024×1536)'],
            'qualities' => ['low' => 'Low (fastest, cheapest)', 'medium' => 'Medium', 'high' => 'High (slowest)'],
            'default_quality' => 'medium',
        ];
    }

    /** JSON body for POST …/images/generations. */
    public static function requestBody(array $config, string $prompt, string $size, string $quality): array
    {
        $p = self::profile((string)$config['model_name']);
        $body = [
            'model'   => $config['deployment_name'],   // v1 API: the deployment name goes in "model"
            'prompt'  => $prompt,
            'n'       => 1,
            'size'    => $size,
            'quality' => $quality,
        ];
        if ($p['family'] === 'dalle') {
            $body['response_format'] = 'b64_json';
        } else {
            $body['output_format'] = 'png';
        }
        return $body;
    }
}
