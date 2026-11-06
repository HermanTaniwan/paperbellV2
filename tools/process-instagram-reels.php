<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require dirname(__DIR__).'/src/OAuthVault.php';
require dirname(__DIR__).'/src/InstagramReelsService.php';
try {(new InstagramReelsService(dirname(__DIR__).'/storage/instagram-reels'))->tick();echo "Instagram Reels: checked.\n";}
catch (Throwable $e) {fwrite(STDERR,"Instagram Reels: worker failed; inspect local storage and configuration.\n");exit(1);}
