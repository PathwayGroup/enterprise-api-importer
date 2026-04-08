# ZAP Localhost Scope For Enterprise API Importer

This file provides two ways to use ZAP safely against the plugin on `https://localhost`:

1. Copy/paste regex rules for the ZAP desktop UI
2. A starter ZAP Automation Framework YAML template

Use a dedicated localhost WordPress admin test account.

## Recommended Strategy

Use a two-layer setup:

1. Broad authenticated context so ZAP can see plugin pages and REST routes
2. Narrow active-scan scope so ZAP does not trigger imports, write config, or delete data

## Context Include Regex

Add these to the ZAP Context include list:

```text
^https://localhost/wp-login\.php(?:\?.*)?$
^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-manage\b.*$
^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-schedules\b.*$
^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-dashboard\b.*$
^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-settings\b.*$
^https://localhost/wp-json/eapi/v1/.*$
```

## Safe Active-Scan Include Targets

Use these for active scan targets:

```text
https://localhost/wp-admin/admin.php?page=eapi-manage
https://localhost/wp-admin/admin.php?page=eapi-schedules
https://localhost/wp-admin/admin.php?page=eapi-dashboard
https://localhost/wp-admin/admin.php?page=eapi-settings
https://localhost/wp-json/eapi/v1/dashboard
https://localhost/wp-json/eapi/v1/dashboard/history
```

If you prefer regex-only active scan scope, use:

```text
^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-manage\b.*$
^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-schedules\b.*$
^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-dashboard\b.*$
^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-settings\b.*$
^https://localhost/wp-json/eapi/v1/dashboard(?:\?.*)?$
^https://localhost/wp-json/eapi/v1/dashboard/history(?:\?.*)?$
```

## Exclusion Regex

Exclude these from active scan:

```text
^https://localhost/wp-admin/admin-post\.php(?:\?.*)?$
^https://localhost/wp-admin/admin-ajax\.php(?:\?.*)?$
^https://localhost/wp-cron\.php(?:\?.*)?$
^https://localhost/xmlrpc\.php(?:\?.*)?$
^https://localhost/wp-json/eapi/v1/dry-run/?$
^https://localhost/wp-json/eapi/v1/import-jobs/?$
^https://localhost/wp-json/eapi/v1/import-jobs/\d+/?$
^https://localhost/wp-json/eapi/v1/import-jobs/\d+/run/?$
^https://localhost/wp-json/eapi/v1/import-jobs/\d+/template-sync/?$
^https://localhost/wp-json/eapi/v1/dashboard\?refresh=1.*$
```

## Authentication Notes

Use form-based authentication against:

```text
https://localhost/wp-login.php
```

Use a dedicated admin test account.

Recommended indicators:

1. Logged in indicator: any plugin admin page in `wp-admin`
2. Logged out indicator: redirect back to `wp-login.php`

## Suggested Manual Sequence In ZAP Desktop

1. Create a new Context for `localhost`
2. Add the Context include regex from this file
3. Configure form-based auth for WordPress login
4. Add one admin test user to the Context
5. Spider the authenticated Context
6. Run passive scan on everything in Context
7. Run active scan only against the safe include targets
8. Review alerts for:
   - Missing auth checks
   - CSRF weaknesses
   - Stored/reflected XSS in admin UI or REST responses
   - SSRF-adjacent remote URL handling
   - Unsafe error disclosure

## ZAP Automation Framework Template

Replace placeholder values before use.

```yaml
env:
  contexts:
    - name: eapi-localhost
      urls:
        - https://localhost
      includePaths:
        - ^https://localhost/wp-login\.php(?:\?.*)?$
        - ^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-manage\b.*$
        - ^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-schedules\b.*$
        - ^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-dashboard\b.*$
        - ^https://localhost/wp-admin/admin\.php\?.*\bpage=eapi-settings\b.*$
        - ^https://localhost/wp-json/eapi/v1/.*$
      excludePaths:
        - ^https://localhost/wp-admin/admin-post\.php(?:\?.*)?$
        - ^https://localhost/wp-admin/admin-ajax\.php(?:\?.*)?$
        - ^https://localhost/wp-cron\.php(?:\?.*)?$
        - ^https://localhost/xmlrpc\.php(?:\?.*)?$
        - ^https://localhost/wp-json/eapi/v1/dry-run/?$
        - ^https://localhost/wp-json/eapi/v1/import-jobs/?$
        - ^https://localhost/wp-json/eapi/v1/import-jobs/\d+/?$
        - ^https://localhost/wp-json/eapi/v1/import-jobs/\d+/run/?$
        - ^https://localhost/wp-json/eapi/v1/import-jobs/\d+/template-sync/?$
        - ^https://localhost/wp-json/eapi/v1/dashboard\?refresh=1.*$
      authentication:
        method: form
        parameters:
          loginPageUrl: https://localhost/wp-login.php
          loginRequestUrl: https://localhost/wp-login.php
          loginRequestBody: log={%username%}&pwd={%password%}&wp-submit=Log+In&redirect_to=https%3A%2F%2Flocalhost%2Fwp-admin%2F&testcookie=1
      sessionManagement:
        method: cookie
      users:
        - name: wp-admin-test
          credentials:
            username: CHANGE_ME
            password: CHANGE_ME

jobs:
  - type: spider
    parameters:
      context: eapi-localhost
      user: wp-admin-test
      maxDuration: 10

  - type: passiveScan-wait
    parameters:
      maxDuration: 10

  - type: activeScan
    parameters:
      context: eapi-localhost
      user: wp-admin-test
      policy: Default Policy
    inputVectors:
      urlQueryStringAndDataDrivenNodes:
        enabled: true
      postData:
        enabled: true
      httpHeaders:
        enabled: false
      cookieData:
        enabled: false
    tests:
      - name: Safe plugin surfaces only
        url: https://localhost/wp-admin/admin.php?page=eapi-manage
      - name: Safe plugin surfaces only
        url: https://localhost/wp-admin/admin.php?page=eapi-schedules
      - name: Safe plugin surfaces only
        url: https://localhost/wp-admin/admin.php?page=eapi-dashboard
      - name: Safe plugin surfaces only
        url: https://localhost/wp-admin/admin.php?page=eapi-settings
      - name: Safe plugin surfaces only
        url: https://localhost/wp-json/eapi/v1/dashboard
      - name: Safe plugin surfaces only
        url: https://localhost/wp-json/eapi/v1/dashboard/history

  - type: report
    parameters:
      template: traditional-html
      reportDir: .
      reportFile: zap-eapi-localhost-report.html
```

## Notes Specific To This Plugin

High-value routes are implemented in:

1. `wp-admin?page=eapi-manage`
2. `wp-admin?page=eapi-schedules`
3. `wp-admin?page=eapi-dashboard`
4. `wp-admin?page=eapi-settings`
5. `wp-json/eapi/v1/dashboard`
6. `wp-json/eapi/v1/dashboard/history`

Potentially destructive routes intentionally excluded:

1. Import create/update REST routes
2. Import run trigger routes
3. Template sync route
4. Dry-run route
5. `admin-post.php` actions like delete/run-now

## Good Follow-Up Manual Tests

After safe automated scanning, manually inspect:

1. Delete import flow confirmation and nonce behavior
2. Import edit/create permissions for non-admin roles
3. REST permission callbacks for all `eapi/v1` routes
4. Twig validation error handling and reflected content paths
5. Endpoint validation and SSRF restrictions under malformed input
```