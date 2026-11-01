# TIMS-BARMM --- Beneficiary Actions, Feature Cleanup, User Monitoring, Simplified Installation, and Sync Approval Workflow

## Objective

Implement the following improvements in TIMS-BARMM. Inspect the existing
application architecture first, then implement the changes in the actual
frontend, backend, database, installation scripts, and synchronization
services where required.

The goals are to:

1.  Let authorized operators choose whether to archive or delete a
    beneficiary.
2.  Remove the obsolete **ID Card** feature.
3.  Replace the Offline Server **User** tab with **User Monitoring**.
4.  Simplify Offline Server installation and move first-time setup into
    a browser-based onboarding flow.
5.  Redesign synchronization so an Offline Server can submit a sync
    request that appears on the Central Server as **Pending Sync
    Request** for review, duplicate detection, conflict resolution, and
    approval.

Do not implement this as a UI-only change. Preserve existing beneficiary
data, device authorization, audit trails, and synchronization
safeguards. Do not use mock data or report success without verifying the
implementation.

------------------------------------------------------------------------

## 1. Beneficiary Management: Archive or Delete

### Current issue

The beneficiary interface currently provides an Archive action but no
Delete action. Add a clear choice for an authorized operator to archive
or delete a beneficiary.

### Required behavior

-   Provide distinct **Archive** and **Delete** actions in the
    appropriate beneficiary interface.
-   Keep Archive as the non-destructive option for removing a record
    from normal active views while retaining its history.
-   Treat Delete as a destructive action and require a confirmation
    dialog that explains the consequences.
-   Enforce permissions on the backend. Hiding the Delete button is not
    sufficient.
-   Record the actor, beneficiary ID, action, timestamp, and relevant
    context in the audit trail.
-   Display accurate success or failure feedback based on the actual
    server response.

### Data integrity and synchronization safeguards

Before implementing deletion, inspect database relationships and
retention requirements.

-   Determine whether beneficiaries are referenced by batches,
    applications, reports, audit logs, pending sync operations, or other
    entities.
-   Do not cascade-delete related records or audit history without an
    explicit and justified rule.
-   If permanent deletion is unsafe or prohibited by business
    requirements, explain the constraint and implement the safest
    supported alternative, such as a guarded delete or soft-delete
    workflow. Do not label a soft delete as permanent deletion.
-   Define how archive and delete actions behave on both Central Server
    and Offline Server.
-   Queue offline actions safely and reconcile them with the Central
    Server.
-   Make retries idempotent so the same action cannot accidentally
    affect a different record or produce duplicate operations.
-   Handle records with pending changes, existing conflicts, or records
    already deleted or archived on the other server.
-   Never silently discard pending operations or audit records.

### Acceptance criteria

-   Authorized operators can choose Archive or Delete.
-   Delete requires confirmation and is enforced by backend permissions.
-   Archive behavior remains functional.
-   Deletion respects data relationships, retention rules, auditability,
    and sync behavior.
-   Automated tests cover successful and rejected actions.

------------------------------------------------------------------------

## 2. Remove the Obsolete ID Card Feature

The **ID Card** tab was implemented during an early stage of development
and is now obsolete.

### Required changes

-   Remove the ID Card tab and navigation links from applicable roles
    and layouts.
-   Remove obsolete UI entry points and actions that exist exclusively
    for this feature.
-   Search for related routes, controllers, components, services, jobs,
    exports, and permissions.
-   Remove feature-specific implementation code only when it is
    confirmed to be unused elsewhere.
-   Do not delete shared beneficiary fields, data, or services merely
    because their names mention an ID if they support another active
    workflow.
-   Do not drop tables or delete stored data without confirming that
    they are no longer required.
-   Ensure retired routes cannot be used to access an obsolete feature
    directly.
-   Keep the remaining application routes and navigation working.

### Acceptance criteria

-   ID Card is no longer presented as an active feature.
-   Obsolete feature-only routes are inaccessible.
-   Unrelated beneficiary, batch, and reporting functions remain intact.
-   Any retained legacy data or code has a documented reason.

------------------------------------------------------------------------

## 3. Replace the Offline Server "User" Tab With "User Monitoring"

### Current issue

The current User tab does not match the intended account lifecycle. The
Manager account is created during Offline Server setup, and the Manager
creates Encoder accounts. Refactor the tab into **User Monitoring**,
focused on showing the users associated with an enrolled Offline Server.

### Required behavior

Display, where available:

-   The Manager associated with the Offline Server.
-   Encoder accounts created or managed by that Manager.
-   User name and account identifier.
-   Role (Manager or Encoder).
-   Account status, such as active or disabled.
-   Creation date and last activity, if tracked by the existing system.
-   Associated Offline Server/device identity.
-   Whether the account information is local, synchronized, or
    acknowledged by the Central Server, if these states exist.

### Enrollment and visibility rules

-   After a device successfully enrolls using a valid enrollment token,
    associate the device with the correct Manager and its authorized
    Encoder accounts.
-   Show only users that the current Manager or administrator is
    permitted to see.
-   Never expose passwords, password hashes, enrollment tokens,
    authorization tokens, or other secrets.
-   Reuse existing user and device models where possible. Do not create
    a duplicate user registry if existing relationships already support
    this.
-   If the device is not enrolled or cannot connect to the Central
    Server, show the real state rather than fabricated user information.
-   Do not let User Monitoring silently create accounts, grant roles, or
    elevate permissions unless that is an explicitly authorized existing
    function.

### Acceptance criteria

-   User is replaced by User Monitoring in the relevant Offline Server
    interface.
-   The associated Manager and Encoder accounts are displayed
    accurately.
-   Visibility respects role and device permissions.
-   No credentials or secrets are exposed.

------------------------------------------------------------------------

## 4. Simplify Offline Server Installation

### Current issue

The current package/setup process asks the installer to provide all of
the following in the terminal:

-   Application URL (for example,
    `http://192.168.1.100/TIMS-BARMM/public`)
-   Central Server URL
-   New database password for the `tims_app` user
-   Admin account email
-   Admin account password
-   MySQL root password

This process is unnecessarily complicated for the intended deployment.
The application should be installed first, and first-time configuration
should be completed through a browser-based onboarding page.

### Desired workflow

#### A. Install the application with minimal manual configuration

Simplify the package and setup scripts so the installer does not need to
manually enter application URLs, Central Server URLs, Manager
credentials, or database credentials through interactive prompts during
a normal installation.

The installation process should:

1.  Install or verify the required local prerequisites.
2.  Copy the application files into the configured web root.
3.  Create or initialize the local database using a secure, supported
    setup mechanism.
4.  Configure local application settings safely.
5.  Set correct file permissions and protect sensitive configuration
    files.
6.  Finish installation and tell the user which local URL to open in a
    browser.

Do not assume every installation uses exactly the same XAMPP path or
URL. Use the existing supported deployment configuration and detect or
clearly report environment-specific requirements.

#### B. First browser access starts onboarding

When the user first opens the Offline Server's local application URL,
show a setup/onboarding page if the device has not yet been configured.

The page should collect the information genuinely required to complete
enrollment, including:

-   **Enrollment token** issued by the authorized Central Server.
-   **Manager username or account identifier**, as required by the
    existing authentication design.
-   **Manager credentials** or the secure process for
    creating/activating the Manager account.
-   **Area name** to identify which geographic or administrative area
    the Offline Server serves.

The Area Name is a human-readable identifier. If the application
requires a canonical area ID, select or resolve it through a validated
list rather than storing an arbitrary label in place of a required
foreign key.

After successful validation, the application should:

1.  Validate the enrollment token with the Central Server.
2.  Discover and securely establish the authoritative Central Server
    connection details from the validated enrollment response.
3.  Register the Offline Server device and associate it with the correct
    area and Manager.
4.  Complete any required initial synchronization.
5.  Persist enrollment and configuration securely.
6.  Mark the device as enrolled and ready.
7.  Redirect to the normal application login or dashboard.

Once setup is complete, subsequent visits must not repeat onboarding
unless the device has been explicitly reset or an authorized recovery
process is initiated.

### Remove unnecessary Central Server URL entry

The installer should not need to manually type the Central Server URL if
the enrollment token is intended to establish the trusted relationship
between the Offline Server and Central Server.

However, **a token cannot safely identify an unknown server by itself
unless the application has a trusted way to discover or verify that
server**. Implement this requirement securely:

-   Use a trusted bootstrap endpoint, a preconfigured trusted service
    URL, or a signed enrollment payload containing the approved Central
    Server endpoint.
-   Validate the token and the server identity before accepting the
    returned endpoint.
-   Use HTTPS with certificate validation for public Central Server
    connections.
-   Do not accept an arbitrary URL from an untrusted token or browser
    input without verification.
-   Do not disable TLS verification or use insecure fallbacks to make
    enrollment work.
-   If a trusted bootstrap URL must still be configured in the package,
    keep it as a deployment-level setting rather than asking the
    installer to enter it for every device.

### Database and credential handling

Removing interactive prompts must not mean removing database security.

-   Do not ship a shared database password, root password, or default
    administrative password inside the package.
-   Do not require or store the MySQL root password in application
    configuration.
-   Use a least-privilege local database account created through a
    secure, documented process.
-   Generate or provision database credentials securely and store them
    in protected configuration.
-   Do not expose `.env`, database credentials, tokens, or setup secrets
    through the web server.
-   Do not overwrite an existing populated database during installation
    or reinstallation.
-   Make setup safe to retry after an interruption.
-   Keep application debug output disabled in production deployments.

### Application URL handling

Avoid requiring the installer to type an Application URL when it can be
derived reliably from the current deployment. If URL discovery is not
reliable in a supported environment, provide a safe fallback
configuration path and document why it is needed.

Do not hardcode `localhost` as the only supported address. The
application may be accessed through a local network IP or configured
hostname.

### Acceptance criteria

-   Normal installation no longer requires the six interactive prompts
    listed above.
-   The installer can complete file and database setup using a secure
    mechanism.
-   First browser access starts onboarding only when the device is not
    configured.
-   Onboarding collects and validates the enrollment token, Manager
    information, and Area Name.
-   Central Server endpoint discovery is authenticated and trusted.
-   Successful onboarding enrolls the device and persists configuration
    securely.
-   Reopening the application does not restart onboarding.
-   Existing populated databases are protected from accidental
    overwrite.
-   Setup errors are actionable and do not expose secrets.

------------------------------------------------------------------------

## 5. Redesign Synchronization as a Central Server Review and Approval Workflow

### Current issue

The current synchronization process does not provide a sufficiently
useful Central Server review workflow for data arriving from an Offline
Server. The existing **Conflict Resolution** tab appears to have limited
practical use.

Introduce a workflow in which an Offline Server submits a
synchronization request and the Central Server reviews the submitted
beneficiary records, compares them with existing Central Server data,
identifies potential duplicates, and allows authorized reviewers to
resolve issues before changes are accepted.

Use the existing Conflict Resolution interface as the foundation where
practical. Do not create a second unrelated conflict-management system.

### Desired end-to-end workflow

#### Step 1: Offline Server submits a sync request

When an authorized user clicks **Sync Now** on the Offline Server:

-   Authenticate and validate the enrolled device.
-   Collect eligible pending beneficiary operations and the associated
    audit events and required metadata.
-   Assign a unique request ID and stable operation IDs.
-   Submit the request to the Central Server.
-   Preserve local pending operations until the Central Server
    acknowledges their receipt and eventual processing status.
-   Show the request status, such as queued, submitted, awaiting review,
    partially accepted, accepted, rejected, or failed, using a
    consistent state model.

A successful upload or receipt acknowledgment must not be presented as
final beneficiary acceptance if Central Server review is still pending.

#### Step 2: Central Server displays Pending Sync Requests

Add or adapt a **Pending Sync Requests** view in the Central Server.
This can be a section within the existing Conflict Resolution tab if
that provides a clearer workflow.

Each request should display relevant information, such as:

-   Request ID and submission time.
-   Source Offline Server/device identity.
-   Associated area.
-   Submitting user or Manager, where available and authorized.
-   Number of new beneficiary records.
-   Number of potential duplicates.
-   Number of validation errors.
-   Number of updates or conflicts.
-   Overall request status.

Do not expose tokens, passwords, or other secrets.

Authorized Central Server reviewers should be able to open a request and
inspect the submitted beneficiary records before deciding what to
accept.

#### Step 3: Compare incoming beneficiaries with Central Server data

For each incoming beneficiary, compare the submitted data with existing
Central Server records to identify:

-   Exact identifier matches.
-   Potential duplicates based on the project's existing beneficiary
    identity and validation rules.
-   Similar names and other identifying attributes where appropriate.
-   Conflicting updates to an existing beneficiary.
-   Missing or invalid required data.
-   Invalid or unresolved area/location references.

Use the existing beneficiary schema and established identity rules. Do
not invent a new identity model without first reviewing the current
application.

Duplicate detection should support human review and must not
automatically merge two people based only on similar names. Where
useful, show the matching fields and explain why a record was flagged.

Location scope must not automatically reject an otherwise valid
beneficiary solely because the record falls outside the Offline Server's
configured scope, in accordance with the previously requested
synchronization behavior. Still validate that submitted location
identifiers are structurally valid and reference legitimate records.

#### Step 4: Use Conflict Resolution for review

Extend the existing Conflict Resolution interface so authorized
reviewers can:

-   Review incoming beneficiary data alongside the possible matching
    Central Server record.
-   Accept a new beneficiary when no blocking issue exists.
-   Reject a submitted record with a recorded reason.
-   Mark a record as a potential duplicate and choose the appropriate
    existing-record action.
-   Resolve conflicting updates using the existing conflict rules.
-   Correct or request correction of invalid data if that workflow is
    supported.
-   Process records individually and, where safe, in bulk.

Bulk actions must not bypass per-record validation or hide unresolved
duplicate warnings.

Do not merge records automatically without explicit rules and
safeguards. Preserve both source and target IDs and record the review
decision.

#### Step 5: Apply approved changes

When a reviewer approves an eligible record:

-   Revalidate the record on the server.
-   Apply the approved operation transactionally.
-   Enforce uniqueness and relationship constraints.
-   Record who approved or rejected the record, when, and why.
-   Preserve the originating Offline Server, request ID, operation ID,
    and audit context.
-   Return a per-record acknowledgment to the Offline Server.
-   Ensure retries do not create duplicate records or apply the same
    operation more than once.

If a request contains a mixture of valid records, potential duplicates,
and invalid records, support per-record results where practical rather
than failing the entire request unnecessarily. Make partial acceptance
explicit.

#### Step 6: Reconcile results on the Offline Server

The Offline Server must receive and persist the Central Server's
authoritative result for each operation.

-   Mark an operation accepted only after confirmed acceptance.
-   Keep rejected records available for review or correction.
-   Keep unresolved conflicts visible.
-   Preserve original rejection reasons and review history.
-   Do not delete local beneficiary records merely because an upload was
    rejected.
-   Do not repeatedly resubmit operations that have already been
    conclusively processed.
-   Make retry and reconciliation idempotent.

### Request and operation state model

Review the existing state model before adding new statuses. Reuse
existing statuses where possible and introduce only the states required
to distinguish receipt from review and final processing.

At minimum, the workflow must distinguish:

-   Locally pending
-   Submitted to Central Server
-   Received / awaiting review
-   Accepted
-   Rejected
-   Potential duplicate / conflict
-   Failed or requiring retry

Do not use one generic `success` status for submission, review, and
final acceptance.

### Security and authorization

-   Only authenticated, enrolled, authorized Offline Servers may submit
    requests.
-   Only authorized Central Server reviewers may approve or reject
    records.
-   Enforce permissions on backend routes and services, not only in the
    UI.
-   Validate device identity, request ownership, operation IDs, and
    payload integrity.
-   Never trust client-supplied approval status or reviewer identity.
-   Keep audit events and approval decisions immutable according to the
    existing audit policy.
-   Do not expose internal secrets in request details or logs.

### Acceptance criteria

-   Sync Now creates or updates a traceable request on the Central
    Server.
-   The request appears in Pending Sync Requests or the integrated
    Conflict Resolution workflow.
-   Reviewers can compare incoming beneficiaries with existing Central
    Server data.
-   Potential duplicates are flagged for review rather than blindly
    inserted or merged.
-   Authorized reviewers can accept or reject records with a recorded
    reason.
-   The Offline Server receives accurate per-record outcomes.
-   Repeated requests and retries do not duplicate records or audit
    events.
-   Local data is preserved when records are rejected or still awaiting
    review.
-   Existing synchronization functionality continues to work.

------------------------------------------------------------------------

## 6. Recommended Additional Improvements

Implement the following recommendations when compatible with the current
architecture and within the task's scope.

### A. Use request-level and record-level statuses

A single sync request may contain many beneficiaries with different
outcomes. Track both the overall request state and each operation's
state so one rejected record does not obscure the status of all other
records.

### B. Show a review summary before approval

Provide a concise summary of new records, exact matches, possible
duplicates, invalid records, and conflicting updates. This helps
reviewers prioritize the work and reduces accidental duplicate creation.

### C. Keep an immutable decision history

For every approval, rejection, merge, or correction, record the
reviewer, timestamp, action, reason, source device, request ID, and
affected record IDs. Preserve earlier decisions if a record is
resubmitted.

### D. Support safe retries and idempotency

Use stable request and operation IDs, server-side uniqueness constraints
where appropriate, and transactional processing. Network retries must
not create duplicate beneficiaries or repeat destructive actions.

### E. Avoid unnecessary manual configuration

Use secure device enrollment to establish device identity, area
association, and trusted Central Server connectivity. Do not place
sensitive secrets in installation packages or ask the installer for
values that can be derived securely.

### F. Protect deletion and identity matching

Permanent deletion and duplicate resolution are high-impact operations.
Use confirmation, permission checks, audit logging, and clear
before/after comparisons. Never auto-merge beneficiaries based solely on
name similarity.

### G. Provide actionable operational feedback

Show the actual reason an installation, enrollment, sync submission,
approval, or record import failed. Keep technical diagnostics available
to authorized administrators without exposing credentials or tokens.

------------------------------------------------------------------------

## 7. Implementation Approach

Before changing code:

1.  Inspect the current project structure, role permissions, navigation,
    beneficiary lifecycle, installation scripts, enrollment flow, sync
    queues, and Conflict Resolution implementation.
2.  Identify the actual source files, routes, services, models,
    migrations, and tests for each requested feature.
3.  Reuse existing services and state models where appropriate; avoid
    duplicate implementations.
4.  Make the smallest coherent changes that satisfy the requirements.
5.  Add database migrations only when required, with safe upgrade
    behavior and no accidental data loss.
6.  Update frontend validation and backend validation consistently.
7.  Add automated tests for success paths, failure paths, permission
    enforcement, retries, duplicates, and data preservation.
8.  Run the relevant tests and verify the real behavior. Do not claim
    tests passed unless they were actually executed.

Do not treat documentation updates as a substitute for implementation.
Do not use mock data or fake success responses. Keep unrelated Central
Server functionality unchanged.

------------------------------------------------------------------------

## 8. Definition of Done

The work is complete only when:

-   Operators can choose Archive or Delete with appropriate safeguards.
-   The obsolete ID Card feature is removed from active navigation and
    access paths.
-   User Monitoring accurately shows the enrolled Offline Server's
    Manager and Encoder accounts.
-   Offline installation no longer requires the unnecessary interactive
    configuration prompts.
-   First browser access runs secure enrollment onboarding when needed.
-   The enrollment token establishes a verified Central Server
    relationship through a trusted mechanism.
-   Sync Now submits a traceable request to the Central Server.
-   Central Server reviewers can inspect incoming beneficiary data and
    potential duplicates in the existing Conflict Resolution workflow.
-   Approval and rejection decisions are enforced server-side, audited,
    and reconciled back to the Offline Server.
-   Local data and pending operations remain safe through failures and
    retries.
-   Automated tests cover the critical workflows and actual results are
    reported.

------------------------------------------------------------------------

## 9. Final Implementation Report

After implementation, report:

1.  A summary of changes for beneficiary archive/delete.
2.  Which ID Card components and routes were removed, and what legacy
    data or code was intentionally retained.
3.  How User Monitoring identifies the Manager and Encoder accounts for
    an enrolled Offline Server.
4.  The simplified installation and first-browser-access enrollment
    flow.
5.  How the enrollment token securely establishes or discovers the
    trusted Central Server endpoint.
6.  The new Sync Now → Pending Sync Request → Review/Conflict Resolution
    → Approval/Rejection → Offline Server reconciliation flow.
7.  The duplicate-detection rules and how uncertain matches are handled.
8.  Files, routes, services, migrations, and permissions changed.
9.  Automated tests executed and their actual results.
10. Remaining limitations, migration considerations, or issues requiring
    a separate decision.

Implement the changes and verify them. If any requirement cannot be
completed safely, explain the exact blocker and leave the affected
behavior in a safe state rather than pretending it is complete.
