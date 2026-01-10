# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a metrics dashboard that tracks PHP CMS codebases (Drupal, TYPO3) over time. It analyzes historical snapshots to measure code quality (LOC, cyclomatic complexity, maintainability index), anti-patterns (service locators, deep arrays, magic keys), and API surface area (plugins, hooks, events, services).

**Live dashboard:** https://dbuytaert.github.io/drupal-core-metrics/

## Commands

### Regenerate metrics data
```bash
composer install                              # Install PHP dependencies (one-time)
python3 scripts/analyze.py --framework drupal # Analyze Drupal (~15-30 min)
python3 scripts/analyze.py --framework typo3  # Analyze TYPO3 (~15-30 min)
python3 scripts/analyze.py --framework all    # Analyze both frameworks
```

### View dashboard locally
```bash
python3 -m http.server 8000   # Then open http://localhost:8000
```

## Architecture

### Data Flow
1. `scripts/analyze.py` - Python orchestrator that:
   - Accepts `--framework` argument (drupal, typo3, all)
   - Clones/updates the appropriate core repository (bare clone)
   - Iterates through semi-annual historical snapshots
   - Exports each snapshot via `git archive`
   - Invokes the framework-specific PHP analyzer
   - Aggregates results into `data/{framework}.json`
   - Also analyzes recent commits for per-commit metric deltas

2. PHP Static Analyzers using nikic/php-parser:
   - `scripts/drupalisms.php` - Drupal-specific patterns (\\Drupal::, #-prefixed keys, hooks via ModuleHandler)
   - `scripts/typo3isms.php` - TYPO3-specific patterns (GeneralUtility::makeInstance, $GLOBALS access, SC_OPTIONS hooks)
   - Both measure per-function metrics: LOC, CCN, MI, anti-patterns
   - Both detect API surface area via AST visitors

3. `index.html` - Static SPA dashboard that:
   - Fetches `data/drupal.json` or `data/typo3.json` based on framework selector
   - Renders charts using Chart.js
   - Shows historical trends, commit activity, and code hotspots

### Framework Configuration
Defined in `analyze.py`:
- **Drupal**: repo `git.drupalcode.org/project/drupal`, analyzes `core/`, starts 2011
- **TYPO3**: repo `github.com/TYPO3/typo3`, analyzes `typo3/sysext/`, starts 2016

### Key Classes in Analyzers
- `FunctionMetricsTracker` - Per-function LOC/CCN/MI tracking
- `AntipatternTracker` - Counts service locators, deep arrays, etc.
- `SurfaceAreaCollector` - Collects distinct API extension points
- Framework-specific AST Visitors for each anti-pattern and surface area type

### Generated Files
- `data/drupal.json` - Drupal metrics (committed)
- `data/typo3.json` - TYPO3 metrics (committed)
- `drupal-core/` - Bare git clone (gitignored)
- `typo3-core/` - Bare git clone (gitignored)
- `output/` - Temporary working directory (gitignored)

## CI/CD

GitHub Actions workflow (`.github/workflows/analyze.yml`) runs daily at midnight UTC:
- Uses matrix strategy to analyze both frameworks in parallel
- Auto-commits changed data files to repository
- Dashboard is hosted via GitHub Pages
