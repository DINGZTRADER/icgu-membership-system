<?php

declare(strict_types=1);

return [
    'allow_local_document_storage' => (bool) env('PRODUCTION_ALLOW_LOCAL_DOCUMENT_STORAGE', false),
    'require_live_mail' => (bool) env('PRODUCTION_REQUIRE_LIVE_MAIL', true),
    'require_mtn_momo' => (bool) env('PRODUCTION_REQUIRE_MTN_MOMO', false),
];
