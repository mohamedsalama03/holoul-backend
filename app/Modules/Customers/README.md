# Customers

B2 owns the one-to-one customer profile and its immutable account ownership. Identity access uses the approved read contracts; this module does not import Identity models or write identity tables.

`CustomerOwnership::scope()` adds the account predicate before list/search execution. Direct and nested reads validate UUIDs and constrain both the account and customer identifier before retrieval. Foreign or malformed references return 404. Read/update actions apply `CustomerPolicy` to visible records; denied actions return 403. These are the ownership patterns for later customer-owned resources, not permission to add those resources in B2.

Profile mutation accepts only `phone`. International numbers require an explicit `+` country prefix, are checked with the maintained libphonenumber library, and are stored as E.164 with a bounded display representation of the same number. Format validation does not prove control of the number. PostgreSQL enforces the one-profile-per-user relationship, customer persona, E.164 shape and immutable owner; profile changes emit an audit event without contact content.
