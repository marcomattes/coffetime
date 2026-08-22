# Security Policy

## Supported versions

Security fixes are provided for:

- the latest tagged release, and
- the current default branch (`main`).

Older tags and forks are not supported. If you are running an older version,
please upgrade before reporting an issue that may already be fixed.

## Reporting a vulnerability

Please do not open a public GitHub issue for a security vulnerability.

Instead, report it privately using
[GitHub's private security advisory feature](security/advisories/new) for
this repository (visible under the "Security" tab). This notifies the
maintainers directly without disclosing the issue publicly.

We will acknowledge new reports as soon as possible and keep you updated as
we investigate and work on a fix.

### What to include

- A description of the vulnerability and its potential impact.
- Steps to reproduce it, ideally a minimal example.
- The affected version or commit.
- Any suggested mitigation, if you have one.

### What not to include

Please do not include, in the report itself or in attached reproduction
material:

- real names or other personal data belonging to actual users,
- private keys, passwords, or other credentials,
- copies of a production database, or
- any other data you would not want disclosed beyond the maintainers.

A minimal, synthetic reproduction (test data, a local throwaway database, a
freshly generated keypair) is preferred and is usually all that is needed to
demonstrate the issue.
