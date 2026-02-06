<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */


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

