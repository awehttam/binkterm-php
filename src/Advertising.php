<?php

namespace BinktermPHP;

class Advertising
{
    private $adsDir;

    public function __construct()
    {
        $this->adsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bbs_ads';
    }

    public function getAdsDir(): string
    {
        return $this->adsDir;
    }

    public function getRandomAd(): ?array
    {
        $ads = $this->listAds();
        if (empty($ads)) {
            return null;
        }

        $name = $ads[array_rand($ads)];
        return $this->getAdByName($name);
    }

    public function listAds(): array
    {
        if (!is_dir($this->adsDir)) {
            return [];
        }

        $files = glob($this->adsDir . DIRECTORY_SEPARATOR . '*.ans') ?: [];
        $ads = [];
        foreach ($files as $file) {
            $ads[] = basename($file);
        }

        sort($ads);
        return $ads;
    }

    public function getAdByName(string $name): ?array
    {
        $safeName = basename($name);
        if (substr($safeName, -4) !== '.ans') {
            $safeName .= '.ans';
        }

        $path = $this->adsDir . DIRECTORY_SEPARATOR . $safeName;
        if (!is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return [
            'name' => $safeName,
            'path' => $path,
            'content' => $content
        ];
    }

    public function renderAdPage(Template $template, ?array $ad): void
    {
        $template->renderResponse('ads/ad_full.twig', [
            'ad' => $ad
        ]);
    }

    public function renderAdModal(Template $template, ?array $ad, string $modalId = 'adModal'): string
    {
        return $template->render('ads/ad_modal.twig', [
            'ad' => $ad,
            'modal_id' => $modalId
        ]);
    }
}
