# B5 baseline

Approved B4 HEAD and intended B5 parent: `5c4e753cdc05736d28cdb8d3b843d8202eb9a175`.
The working tree was clean on `main` before implementation. B5 implements only
Discovery and Proposals under the approved B0 architecture. B6 is not authorized.

The 15 existing migrations remain unchanged. B5 uses additive migrations,
module-owned writes and an outer transactional workflow for request/proposal
coordination. Verification evidence will be recorded after the complete gate.
