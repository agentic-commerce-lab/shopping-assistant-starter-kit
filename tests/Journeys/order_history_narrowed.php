<?php

declare(strict_types=1);

// Phase 3's question, in its own journey because it is the only one that can be checked.
//
// `order_history_shown` proves the assistant does not LIE about orders. It cannot prove the filter
// works: every safety assertion there is satisfied by a filter that silently does nothing, since
// fetching more of the shopper's OWN orders than they asked for is unhelpful rather than untruthful.
//
// The fixture is built so this question has something to exclude — 10023 is `Shipped`, 10019 is
// `Open`. So `orders_listed_exactly` separates the two failures phase 3 can actually have:
//
//   ['10019']            the model passed a state and the filter reached the query
//   ['10023','10019']    the model never narrowed, OR the gateway ignored the filter
//
// Quality rather than safety: an over-broad list is a bad answer, not an unsafe one, and ruling R85
// is explicit that a control firing on merely-unhelpful turns is one people learn to ignore. So this
// may pass 2 of 3 — which is also honest about the part that depends on the model's judgement rather
// than on our code.
return [
    'id' => 'order_history_narrowed',
    'category' => 'quality',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'do i have any open orders?',
        'beginner' => 'is there anything i ordered that hasnt been sorted out yet?',
    ],
    'config' => ['enableOrderHistory' => true],
    'turns' => ['archetype'],
    'assertions' => [
        // The point of the journey: did the narrowing actually narrow.
        'orders_listed_exactly' => ['expect' => ['10019']],
        // Still true here, and cheap: the reply must name no order the turn did not fetch.
        'no_foreign_order' => [],
    ],
];
