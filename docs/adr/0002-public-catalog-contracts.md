# Public workflow catalog contracts

Status: accepted
Date: 2026-11-12

WorkflowCatalogData's guard, effect and principal members belong to the public Workflows capability.
GuardCatalogEntryData and PrincipalKindData live alongside that catalog, preserving their existing
keys and defaults. Hosts still populate the vocabulary and enforce authoring permissions; public
contracts do not export their implementations or policy.

PrincipalKindData currently carries Accounts RoleOptionData for its role-kind options. Workflows
requires `splicewire/laravel-beam-accounts` so standalone schema/TypeScript generation can load that
declared nested type without Tower. A docblock or Composer suggest would leave it unavailable.
Duplicating value/label or moving Accounts' role vocabulary would create a second owner.

This dependency also installs the Accounts provider and its Fortify, Passkeys, Sanctum, Inertia,
tenancy and permission dependencies. Laravel hosts discover its existing config/bindings and default
account routes; its migration stubs remain publish-only. Existing configuration gates are unchanged.
Consumers that intentionally exclude account routes retain the existing Accounts configuration gate.

Tower's old DTO names remain thin deprecated subclasses. Public projections select fully qualified
public declarations and include RoleOptionData in the same generated workflow slice. A non-role
options vocabulary would require a separate declared type decision; it does not widen this contract.

The distribution boundary remains `splicewire/beam docs/adr/0001-public-beam-resource-projections.md`:
public resource projections carry public PHP declarations, generated through the existing host
pipeline. Neither Tower implementation nor its private type bundle is published by this move.
