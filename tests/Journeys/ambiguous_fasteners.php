<?php

declare(strict_types=1);

// The same ambiguity as `ambiguous_across_vehicles`, with every lexical clue taken away.
//
// That journey is the easy axis and passes on both measured models. "Reifen" spans three vehicle
// worlds, but the product NAMES still leak which world they belong to — "Ganzjahresreifen" is a car
// tyre to anyone who reads German, "Marathon Plus" is a bicycle tyre to anyone who rides. A model
// can be right there for reasons that have nothing to do with noticing an ambiguity.
//
// Fasteners remove that. No fastener name in the catalogue contains a vehicle word, and BOTH worlds
// hold a product called exactly "Innensechskantschraube" — same name, different vehicle, nothing to
// tell them apart. The category path is the only separator, and neither the model nor the shopper is
// ever shown one. This is the shape the original report described: *"searching for bolts can direct
// you to results for car, motorcycles or bikes that could lead to issues"*.
//
// **The first version of this journey measured a fiction, and it is worth saying so.** Its slice of
// the bicycle category "Schrauben & Muttern" held 15 washers, 7 nuts and no screw at all, so
// answering "Schrauben" with motorcycle parts only was CORRECT — there was nothing else to find.
// The red it produced was a bug in the catalogue, not in the assistant. A journey about ambiguity
// is worthless unless both readings actually exist in the data; check that before reading a
// failure as a finding.
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
