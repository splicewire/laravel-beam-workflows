<?php

// localization 37: workflows' own status presentation (namespace beam-workflows, allocated by beam's TextNamespaces).
// The English is byte-identical to the literal each producer stored before.
return [
    'transition' => 'transition::transition → :places',
    'migrated' => 'migrated: :old → :new (version pinned to :version)',
    // A row whose description IS its bare state (emitted with no message): the state word.
    'state' => [
        'queued' => 'queued',
        'running' => 'running',
        'complete' => 'complete',
        'failed' => 'failed',
        'skipped' => 'skipped',
    ],
];
