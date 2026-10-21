<?php

declare(strict_types=1);

// Under an excluded prefix: strtoupper() here must stay the native function, not
// FixtureHooks\captured_strtoupper, so no sentinel is written to PLUG_MARKER.
echo \json_encode(['upper' => \strtoupper('xyz')]), "\n";
