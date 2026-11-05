# Session idle handling

`SESSION_LIFETIME=60` is the single owner idle-time setting, in minutes. It feeds the
server idle enforcement and the deadline supplied to every authenticated page and
successful response. `ADMIN_IDLE_TIMEOUT` is no longer used. Apply configuration
changes with `php artisan config:clear` (or rebuild the deployment's config cache).

The existing `STAFF_IDLE_TIMEOUT` policy remains separate. Laravel's shared session
storage retention is at least the larger role timeout, so garbage collection cannot
remove either role's session prematurely. The owner is still signed out at the
configured owner idle deadline, regardless of that storage retention period.

Successful page navigation, form submissions, application fetch requests and genuine
browser activity extend the idle deadline. Failed requests and automatic inventory,
verification or session-status polling do not. The client consumes server-rendered
session metadata and response headers rather than maintaining a separate idle duration.

Visible tabs share status polling and activity throttles through browser storage.
Status checks run at most once a minute per tab and ordinarily once a minute across
tabs; blocked storage falls back to per-tab throttling. HTTP 429 honors Retry-After,
and network/server failures back off. Neither a failed request nor a local countdown
alone signs the user out. The server must confirm authentication has ended with 401.

A warning appears two minutes before expiry. Stay signed in sends an explicit
activity request and refreshes the server deadline on success. A lost connection
keeps the page and its work visible while checks retry.

Only one signed-in device is allowed per account. A new login rotates the active
session token. Replaced session records remain until normal expiration so they can
show “You signed in on another device” when rejected. Both JSON status checks and
page navigation preserve that message on the login page.
