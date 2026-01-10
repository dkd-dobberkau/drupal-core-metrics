# PHP CMS Core Metrics

A dashboard that tracks PHP CMS codebases over time: lines of code, complexity, maintainability, anti-patterns, and API surface area.

**Supported frameworks:** Drupal, TYPO3

**View the dashboard:** https://dbuytaert.github.io/drupal-core-metrics/


## Metrics

### Code quality
- **SLOC**: Source lines of code (excluding blanks and comments)
- **Cyclomatic complexity**: Decision paths in code. Lower is simpler.
- **Maintainability index**: 0-100 score. Higher is easier to maintain.

### Anti-patterns
Code patterns with known downsides. Tracked per 1k lines.

**Drupal:**
| Pattern | Description |
|---------|-------------|
| Magic keys | `#`-prefixed array keys. Inherent to Drupal's render array architecture. |
| Deep arrays | 3+ levels of nesting. Hard to read and refactor. |
| Service locators | `\Drupal::service()` calls. Hides dependencies, hinders testing. |

**TYPO3:**
| Pattern | Description |
|---------|-------------|
| Service locators | `GeneralUtility::makeInstance()` calls. Bypasses dependency injection. |
| Globals access | Direct `$GLOBALS['TYPO3_CONF_VARS']` access. |
| Deep arrays | 3+ levels of nesting. Hard to read and refactor. |

### API surface area
Distinct extension points in each CMS. A larger surface may correlate with a steeper learning curve.


## Running locally

**Prerequisites:** PHP 8.1+, Python 3, Composer

### Regenerating data

```bash
composer install                              # Install dependencies
python3 scripts/analyze.py --framework drupal # Analyze Drupal (15-30 min)
python3 scripts/analyze.py --framework typo3  # Analyze TYPO3 (15-30 min)
python3 scripts/analyze.py --framework all    # Analyze both frameworks
```

This generates `data/drupal.json` and/or `data/typo3.json`. The `index.html` file is static and does not need to be regenerated.

### Viewing the dashboard

The dashboard loads data via `fetch()`, which requires an HTTP server (browsers block this for local files). Start a simple server:

```bash
python3 -m http.server 8000
```

Then open http://localhost:8000 in your browser. Use the framework selector to switch between Drupal and TYPO3.


## Contributing

Questions or ideas? Open an issue or PR.
