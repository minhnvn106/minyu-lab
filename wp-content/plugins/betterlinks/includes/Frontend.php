<?php

namespace BetterLinks;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Frontend\LinkChecker;

class Frontend {
    public function __construct() {
        new LinkChecker;
    }
}
