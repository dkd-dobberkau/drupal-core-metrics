# Installation Guide

## Requirements

| Dependency | Version | Purpose |
|------------|---------|---------|
| PHP | 8.1+ | Static analysis via nikic/php-parser |
| Python | 3.8+ | Orchestrates git operations and data aggregation |
| Composer | 2.x | PHP dependency management |
| Git | 2.x | Clones and navigates CMS repositories |

### Disk Space

| Framework | Clone Size | Working Space | Total |
|-----------|------------|---------------|-------|
| Drupal | ~2 GB | ~500 MB | ~2.5 GB |
| TYPO3 | ~1 GB | ~500 MB | ~1.5 GB |

## Setup

```bash
# Clone this repository
git clone https://github.com/dbuytaert/drupal-core-metrics.git
cd drupal-core-metrics

# Install PHP dependencies
composer install
```

## Running the Analysis

### Analyze a Single Framework

```bash
# Drupal (analyzes core/ from git.drupalcode.org)
python3 scripts/analyze.py --framework drupal

# TYPO3 (analyzes typo3/sysext/ from github.com/TYPO3/typo3)
python3 scripts/analyze.py --framework typo3
```

### Analyze Both Frameworks

```bash
python3 scripts/analyze.py --framework all
```

### Expected Runtime

| Phase | First Run | Subsequent Runs |
|-------|-----------|-----------------|
| Git clone | 5-10 min | ~1 min (fetch only) |
| Historical snapshots | 15-25 min | 15-25 min |
| Recent commits | 2-5 min | 2-5 min |
| **Total** | **25-40 min** | **20-30 min** |

## Viewing the Dashboard

The dashboard uses `fetch()` to load JSON data, which requires an HTTP server:

```bash
python3 -m http.server 8000
```

Open http://localhost:8000 in your browser. Use the toggle buttons to switch between Drupal and TYPO3.

## Output Files

After running the analysis:

```
data/
├── drupal.json    # Drupal metrics (~5 MB)
└── typo3.json     # TYPO3 metrics (~5 MB)
```

These files are committed to the repository and used by the dashboard.

## Troubleshooting

### PHP memory errors

The analyzers use up to 2GB of memory for large snapshots. If you see memory errors:

```bash
# Increase PHP memory limit
php -d memory_limit=4G scripts/drupalisms.php /path/to/code
```

### Git clone fails

If cloning times out, try cloning manually:

```bash
# Drupal
git clone --bare https://git.drupalcode.org/project/drupal.git drupal-core

# TYPO3
git clone --bare https://github.com/TYPO3/typo3.git typo3-core
```

Then re-run the analysis - it will detect the existing clone and use it.

### Parser errors

Some historical PHP files may not parse with modern PHP. The analyzers track parse errors in the output (`parseErrors` field) but continue processing. A few errors are normal for older snapshots.

## Verifying the Setup

Quick syntax check without running full analysis:

```bash
# Check PHP analyzers
php -l scripts/drupalisms.php
php -l scripts/typo3isms.php

# Test with a small PHP directory
php scripts/typo3isms.php /path/to/any/php/project
```

Expected output: JSON with metrics like `loc`, `ccn`, `mi`, `antipatterns`, etc.
