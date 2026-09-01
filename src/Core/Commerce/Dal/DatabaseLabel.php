<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

/**
 * @internal the engine's name in front of its version, for {@see DalVectorSupport::describe()}
 *
 * **Why a merchant needs the name and not just the number.**
 * {@see \Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability::fastPathUnmet()} reads "the
 * indexed MariaDB store needs MariaDB 11.7 or newer … and this shop runs %s". Filling that with the
 * raw `VERSION()` made a MySQL shop render "… needs MariaDB 11.7 or newer … and this shop runs
 * 8.0.46", whose obvious reading is that the shop is on an OLD MARIADB — sending the merchant to
 * upgrade MariaDB, the one action that message's closing sentence exists to rule out. A reader made
 * exactly that inference, which is the only evidence a piece of prose ever really gets.
 *
 * Its own class rather than a method on {@see DalVectorSupport}, for the reason {@see \Swag\AssistantStarterKit\ShopInfo\StoreWidth}
 * gives for the same move: Mago's complexity budget is per class, and naming a database is not the
 * job of the thing that probes one.
 */
final class DatabaseLabel
{
    private function __construct() {}

    /**
     * @param string $version `VERSION()` — MariaDB puts its own name in here, MySQL does not
     * @param string $comment `@@version_comment` — where MySQL does identify itself
     */
    public static function of(string $version, string $comment): string
    {
        if ($version === '') {
            return 'an unknown database';
        }

        // Anything naming neither is called MySQL: every engine in that group is MySQL-compatible
        // and equally unable to serve the indexed store, which is the only decision this informs.
        if (stripos($version . ' ' . $comment, needle: 'maria') === false) {
            return 'MySQL ' . $version;
        }

        // MariaDB's build suffix goes, because its own name is part of it and the rest
        // (`-ubu2404`) is not a version a merchant recognises — least of all as the thing to compare
        // against the "11.7 or newer" in the same sentence. A MySQL fork's suffix is left alone: there
        // it carries the distribution's own build, which is information rather than noise.
        return 'MariaDB ' . (preg_match('/^\d+(?:\.\d+)*/', $version, $matches) === 1 ? $matches[0] : $version);
    }
}
