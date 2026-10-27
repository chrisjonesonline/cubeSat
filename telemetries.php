<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "The legacy local telemetry demo has been retired. Satellite data is now loaded from SatNOGS DB.\n";
