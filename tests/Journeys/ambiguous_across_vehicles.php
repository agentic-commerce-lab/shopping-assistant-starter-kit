<?php

declare(strict_types=1);

// A word that means three different products, and a shopper who has not said which.
//
// The `parts` catalogue is a slice of a real B2B shop that sells for cars, motorcycles and
// bicycles. It holds 24 tyres in each of those three worlds, so "Reifen" matches all of them and no
// search can tell which one is meant. That is the shape this journey exists for, and it is not
// reproducible in the generated catalogues: those were designed by this project, and a catalogue you
// designed cannot surprise you with an ambiguity.
//
// **What is asserted is an absence, not a question.** See `AmbiguityNotResolvedSilently` — a turn
// passes by asking OR by rendering across more than one world, and fails only by picking one world
// in silence. Pinning "asks a question" would pin one solution to the problem and rule out the other
// honest one.
//
// Its companion is `qualified_question_not_interrogated`, and neither is worth much alone: this one
// alone is passed by an assistant that asks "for which vehicle?" about everything.
return [
    'id' => 'ambiguous_across_vehicles',
    'category' => 'grounding',
    'catalog' => 'parts',
    'runs' => 3,
    // Neither phrasing names a vehicle, and both are what someone actually types. The expert states
    // a need, the beginner describes a symptom; the ambiguity is identical and unresolvable either
    // way.
    'archetypes' => [
        'expert' => 'ich brauche neue reifen',
        'beginner' => 'meine reifen sind ziemlich abgefahren, was habt ihr da?',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'ambiguity_not_resolved_silently' => [
            'groups' => [
                'Autoteile und Zubehör' => ['auto', 'pkw', 'wagen'],
                'Motorrad- und Rollerteile' => ['motorrad', 'roller', 'moped'],
                'Fahrradteile' => ['fahrrad', 'bike', 'rad'],
            ],
            // The generic form resolves it just as well: a reply need not enumerate the catalogue.
            'axis' => ['fahrzeug', 'welches gefährt'],
        ],
        // A deflection must not improvise a product on the way past the question, and the prices in
        // this catalogue are invented — so a figure in the prose is doubly wrong here.
        // A turn that shows nothing and asks nothing fails both of these, and only this one
        // says which half went wrong.
        'renders_at_least' => ['count' => 1],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
