# Offline property timezone lookup

`npm ci --prefix tools/timezone --ignore-scripts` installs the pinned `geo-tz`
dependency. PHP invokes `lookup.cjs` with validated coordinates; the lookup reads
bundled geographic boundaries locally and makes no network requests. The
comprehensive dataset keeps country-appropriate IANA names such as
`America/Toronto`, including daylight-saving rules via PHP's timezone database.

The runtime image installs Node and copies the pinned lookup dependencies from
the timezone build stage. The backend CI job installs the same data. Invalid
coordinates, ocean-only results, multiple possible zones, and lookup failures
remain unresolved rather than falling back to the operator's timezone as fact.

Source and maintenance: https://github.com/evansiroky/node-geo-tz (MIT code).
The geographic data is derived from
https://github.com/evansiroky/timezone-boundary-builder and OpenStreetMap under
ODbL: https://opendatacommons.org/licenses/odbl/1-0/ . The installed package keeps
its license files and data. Update the pinned version and lockfile together;
run the Canadian regional and international timezone tests before deployment.
