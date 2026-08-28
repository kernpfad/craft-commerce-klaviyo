<?php

namespace kernpfad\commerceklaviyo\web\assets\onsite;

use craft\web\AssetBundle;

class OnsiteTrackingAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;
        $this->js = ['js/onsite-tracking.js'];

        parent::init();
    }
}
