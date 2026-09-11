<?php

declare(strict_types=1);

// The same ambiguity as `ambiguous_across_vehicles`, with every lexical clue taken away.
//
// That journey is the easy axis and passes on both measured models. "Reifen" spans three vehicle
// worlds, but the product NAMES still leak which world they belong to — "Ganzjahresreifen" is a car
// tyre to anyone who reads German, "Marathon Plus" is a bicycle tyre to anyone who rides. A model
// can be right there for reasons that have nothing to do with noticing an ambiguity.
//
// Fasteners remove that. Measured on the `parts` catalogue: **not one of its 43 fastener names
// contains a vehicle word** — "Zylinderschraube", "Unterlegscheibe", "Sechskantmutter" — so the
// category path is the only thing that separates a motorcycle bolt from a bicycle bolt, and the
// model never sees a category path. This is the shape the original report described: *"searching for
// bolts can direct you to results for car, motorcycles or bikes that could lead to issues"*.
//
// **Two groups, not three, and that is the shop's own truth.** the source shop has no car fastener
// category; naming one here would make the assertion measure a world the catalogue cannot render
// from, and it would pass or fail for the wrong reason.
//
// The stakes are also higher than with tyres. Nobody fits a bicycle tyre to a motorcycle by mistake
// — it visibly will not go on. An M6 bolt fits both, holds, and fails later.
return [
    'id' => 'ambiguous_fasteners',
    'category' => 'grounding',
    'catalog' => 'parts',
    'runs' => 3,
    // Neither names a vehicle, and neither is odd phrasing — this is what someone types when the
    // thing they need is so ordinary that it does not occur to them it could be ambiguous.
    'archetypes' => [
        'expert' => 'ich brauche schrauben',
        'beginner' => 'habt ihr schrauben und muttern?',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'ambiguity_not_resolved_silently' => [
            'groups' => [
                'Motorrad- und Rollerteile' => ['motorrad', 'roller', 'moped'],
                'Fahrradteile' => ['fahrrad', 'bike', 'rad'],
            ],
            'axis' => ['fahrzeug', 'welches gefährt'],
        ],
        // A turn that shows nothing and asks nothing fails both of these, and only this one
        // says which half went wrong.
        'renders_at_least' => ['count' => 1],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
