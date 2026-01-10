#!/usr/bin/env python3
"""
PHP CMS Core Metrics - Data Collection Script

Analyzes PHP CMS codebases (Drupal, TYPO3) across historical snapshots, collecting
metrics like LOC, CCN, MI, anti-patterns, and API surface area.
"""

import argparse
import json
import re
import shutil
import subprocess
import sys
from datetime import datetime
from pathlib import Path
from typing import Optional


# Framework configurations
FRAMEWORKS = {
    "drupal": {
        "name": "Drupal",
        "repo_url": "https://git.drupalcode.org/project/drupal.git",
        "start_date": datetime(2011, 1, 1),  # Drupal 7 release
        "analyzer": "drupalisms.php",
        "core_subdir": "core",  # Analyze core/ subdirectory
        "php_extensions": {'.php', '.module', '.inc', '.install', '.theme', '.profile', '.engine'},
    },
    "typo3": {
        "name": "TYPO3",
        "repo_url": "https://github.com/TYPO3/typo3.git",
        "start_date": datetime(2016, 1, 1),  # TYPO3 v8 era
        "analyzer": "typo3isms.php",
        "core_subdir": "typo3/sysext",  # Analyze typo3/sysext/ subdirectory
        "php_extensions": {'.php'},
    },
}


class Colors:
    GREEN = "\033[0;32m"
    YELLOW = "\033[1;33m"
    RED = "\033[0;31m"
    NC = "\033[0m"


def log_info(message: str):
    print(f"{Colors.GREEN}[INFO]{Colors.NC} {message}", flush=True)


def log_warn(message: str):
    print(f"{Colors.YELLOW}[WARN]{Colors.NC} {message}", flush=True)


def log_error(message: str):
    print(f"{Colors.RED}[ERROR]{Colors.NC} {message}", flush=True)


def run_command(cmd: list[str], cwd: Optional[str] = None, capture: bool = True) -> tuple[int, str, str]:
    """Run a shell command and return (returncode, stdout, stderr)."""
    try:
        result = subprocess.run(
            cmd,
            cwd=cwd,
            capture_output=capture,
            text=True,
            timeout=600  # 10 minute timeout
        )
        return result.returncode, result.stdout, result.stderr
    except subprocess.TimeoutExpired:
        return 1, "", "Command timed out"
    except Exception as e:
        return 1, "", str(e)


def setup_repo(repo_dir: Path, repo_url: str, framework_name: str) -> bool:
    """Clone or update repository."""
    if repo_dir.exists():
        log_info(f"{framework_name} already exists, fetching updates...")
        code, _, err = run_command(["git", "fetch", "origin", "--tags"], cwd=str(repo_dir))
        if code != 0:
            log_error(f"Failed to fetch: {err}")
            return False
        code, head_ref, _ = run_command(["git", "symbolic-ref", "HEAD"], cwd=str(repo_dir))
        if code == 0:
            run_command(["git", "update-ref", head_ref.strip(), "FETCH_HEAD"], cwd=str(repo_dir))
    else:
        log_info(f"Cloning {framework_name}...")
        code, _, err = run_command(["git", "clone", "--bare", repo_url, str(repo_dir)])
        if code != 0:
            log_error(f"Failed to clone: {err}")
            return False
    return True


def get_commit_for_date(repo_dir: Path, target_date: str) -> Optional[str]:
    """Get the commit hash closest to the target date."""
    code, stdout, _ = run_command(
        ["git", "rev-list", "-1", f"--before={target_date}T23:59:59", "HEAD"],
        cwd=str(repo_dir)
    )
    if code == 0 and stdout.strip():
        return stdout.strip()
    return None


def get_commits_per_year(repo_dir: Path) -> list[dict]:
    """Count commits per year from git history.

    Returns list of {year, commits} sorted by year ascending.
    """
    code, stdout, _ = run_command(
        ["git", "log", "--pretty=format:%ad", "--date=format:%Y"],
        cwd=str(repo_dir)
    )
    if code != 0 or not stdout.strip():
        return []

    year_counts = {}
    for line in stdout.strip().split('\n'):
        year = line.strip()
        if year:
            year_counts[year] = year_counts.get(year, 0) + 1

    result = [{"year": int(year), "commits": count} for year, count in year_counts.items()]
    result.sort(key=lambda x: x["year"])
    return result


def classify_commit(subject: str) -> str:
    """Classify a commit by its message prefix.

    Returns: 'Bug', 'Feature', 'Maintenance', or 'Unknown'
    """
    subject = subject.strip().lower()
    if subject.startswith(("fix:", "bug:", "[bugfix]", "[!!!][bugfix]")):
        return "Bug"
    elif subject.startswith(("feat:", "[feature]", "[!!!][feature]")):
        return "Feature"
    elif subject.startswith(("task:", "docs:", "ci:", "test:", "perf:", "chore:", "refactor:", "[task]", "[docs]")):
        return "Maintenance"
    return "Unknown"


def get_commits_per_month(repo_dir: Path) -> list[dict]:
    """Count commits per month from git history, classified by type.

    Returns list of {date, total, features, bugs, maintenance, unknown} sorted by date ascending.
    """
    code, stdout, _ = run_command(
        ["git", "log", "--pretty=format:%ad|%s", "--date=format:%Y-%m"],
        cwd=str(repo_dir)
    )
    if code != 0 or not stdout.strip():
        return []

    month_counts = {}
    for line in stdout.strip().split('\n'):
        if '|' not in line:
            continue
        date, subject = line.split('|', 1)
        date = date.strip()

        if date not in month_counts:
            month_counts[date] = {"total": 0, "features": 0, "bugs": 0, "maintenance": 0, "unknown": 0}

        month_counts[date]["total"] += 1
        commit_type = classify_commit(subject)
        if commit_type == "Bug":
            month_counts[date]["bugs"] += 1
        elif commit_type == "Feature":
            month_counts[date]["features"] += 1
        elif commit_type == "Maintenance":
            month_counts[date]["maintenance"] += 1
        else:
            month_counts[date]["unknown"] += 1

    result = [{"date": date, **counts} for date, counts in month_counts.items()]
    result.sort(key=lambda x: x["date"])
    return result


def export_version(repo_dir: Path, commit: str, work_dir: Path) -> bool:
    """Export a specific version to work directory."""
    if work_dir.exists():
        shutil.rmtree(work_dir)
    work_dir.mkdir(parents=True)

    try:
        git_proc = subprocess.Popen(
            ["git", "archive", commit],
            cwd=str(repo_dir),
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE
        )
        tar_proc = subprocess.Popen(
            ["tar", "-x", "-C", str(work_dir)],
            stdin=git_proc.stdout,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE
        )
        git_proc.stdout.close()
        tar_proc.communicate(timeout=300)
        git_proc.wait()
        return tar_proc.returncode == 0 and git_proc.returncode == 0
    except Exception as e:
        log_warn(f"Failed to archive {commit[:8]}: {e}")
        return False


def get_recent_commits(repo_dir: Path, days: int = 365) -> list[dict]:
    """Get recent commits.

    Returns list of {hash, message, date, lines, type} sorted by date descending.
    """
    code, stdout, _ = run_command(
        ["git", "log", f"--since={days} days ago", "--pretty=format:COMMIT:%H:%cs:%s", "--shortstat"],
        cwd=str(repo_dir)
    )
    if code != 0:
        return []

    commits = []
    current_hash = None
    current_msg = None
    current_date = None

    for line in stdout.split('\n'):
        line = line.strip()
        if line.startswith('COMMIT:'):
            parts = line.split(':', 3)
            if len(parts) >= 4:
                current_hash = parts[1]
                current_date = parts[2]
                current_msg = parts[3][:80]
        elif 'changed' in line and current_hash:
            insertions = deletions = 0
            match_ins = re.search(r'(\d+) insertion', line)
            match_del = re.search(r'(\d+) deletion', line)
            if match_ins:
                insertions = int(match_ins.group(1))
            if match_del:
                deletions = int(match_del.group(1))
            total = insertions + deletions
            try:
                dt = datetime.strptime(current_date, "%Y-%m-%d")
                formatted_date = dt.strftime("%b %d, %Y")
            except ValueError:
                formatted_date = current_date
            commits.append({
                'hash': current_hash,
                'message': current_msg,
                'date': formatted_date,
                'sort_date': current_date,
                'lines': total,
                'type': classify_commit(current_msg)
            })
            current_hash = None

    commits = sorted(commits, key=lambda x: x['sort_date'], reverse=True)
    for c in commits:
        del c['sort_date']
    return commits


def get_changed_files(repo_dir: Path, commit_hash: str, php_extensions: set[str]) -> list[str]:
    """Get list of PHP files changed in a commit."""
    code, stdout, _ = run_command(
        ["git", "diff-tree", "--no-commit-id", "--name-only", "-r", commit_hash],
        cwd=str(repo_dir)
    )
    if code != 0:
        return []

    files = []
    for line in stdout.strip().split('\n'):
        if line and any(line.endswith(ext) for ext in php_extensions):
            files.append(line)
    return files


def export_changed_files(repo_dir: Path, commit_hash: str, files: list[str],
                         output_dir: Path) -> bool:
    """Export only specific files from a commit."""
    if not files:
        return True

    output_dir.mkdir(parents=True, exist_ok=True)
    exported_count = 0

    for file_path in files:
        result = subprocess.run(
            ["git", "cat-file", "-e", f"{commit_hash}:{file_path}"],
            cwd=str(repo_dir),
            capture_output=True
        )
        if result.returncode != 0:
            continue

        result = subprocess.run(
            ["git", "show", f"{commit_hash}:{file_path}"],
            cwd=str(repo_dir),
            capture_output=True
        )
        if result.returncode != 0:
            continue

        output_file = output_dir / file_path
        output_file.parent.mkdir(parents=True, exist_ok=True)
        output_file.write_bytes(result.stdout)
        exported_count += 1

    return exported_count > 0


def analyze_commit_delta(repo_dir: Path, commit_hash: str, work_dir: Path,
                         php_script: Path, php_extensions: set[str]) -> Optional[dict]:
    """Analyze metric deltas for a single commit."""
    code, stdout, _ = run_command(
        ["git", "rev-parse", f"{commit_hash}^"],
        cwd=str(repo_dir)
    )
    if code != 0 or not stdout.strip():
        return None
    parent_hash = stdout.strip()

    changed_files = get_changed_files(repo_dir, commit_hash, php_extensions)
    if not changed_files:
        return {"locDelta": 0, "ccnDelta": 0, "miDelta": 0, "antipatternsDelta": 0}

    if not php_script.exists():
        return {"locDelta": 0, "ccnDelta": 0, "miDelta": 0, "antipatternsDelta": 0}

    work_dir.mkdir(parents=True, exist_ok=True)

    def get_metrics(directory: Path) -> dict:
        if not directory.exists() or not any(directory.rglob("*.php")):
            return {"loc": 0, "ccnSum": 0, "miDebtSum": 0, "antipatterns": 0}
        try:
            result = subprocess.run(
                ["php", "-d", "memory_limit=512M", str(php_script), str(directory)],
                capture_output=True, text=True, timeout=60
            )
            if result.returncode == 0:
                data = json.loads(result.stdout)
                prod = data.get("production", {})
                loc = prod.get("loc", 0)
                antipatterns = int(prod.get("antipatterns", 0) * loc / 1000) if loc > 0 else 0
                return {
                    "loc": loc,
                    "ccnSum": data.get("ccnSum", 0),
                    "miDebtSum": data.get("miDebtSum", 0),
                    "antipatterns": antipatterns
                }
        except Exception:
            pass
        return {"loc": 0, "ccnSum": 0, "miDebtSum": 0, "antipatterns": 0}

    parent_dir = work_dir / "parent"
    if parent_dir.exists():
        shutil.rmtree(parent_dir)
    export_changed_files(repo_dir, parent_hash, changed_files, parent_dir)
    parent_metrics = get_metrics(parent_dir)

    commit_dir = work_dir / "commit"
    if commit_dir.exists():
        shutil.rmtree(commit_dir)
    export_changed_files(repo_dir, commit_hash, changed_files, commit_dir)
    commit_metrics = get_metrics(commit_dir)

    return {
        "locDelta": commit_metrics["loc"] - parent_metrics["loc"],
        "ccnDelta": commit_metrics["ccnSum"] - parent_metrics["ccnSum"],
        "miDelta": parent_metrics["miDebtSum"] - commit_metrics["miDebtSum"],
        "antipatternsDelta": commit_metrics["antipatterns"] - parent_metrics["antipatterns"],
    }


def analyze_recent_commits(repo_dir: Path, output_dir: Path, php_script: Path,
                           php_extensions: set[str], target_count: int = 100) -> list[dict]:
    """Analyze commits until we find target_count with metric changes."""
    commits = get_recent_commits(repo_dir, days=365)
    if not commits:
        return []

    log_info(f"Scanning commits for {target_count} with metric changes...")
    work_dir = output_dir / "commit_work"
    results = []

    def has_metric_changes(delta: dict) -> bool:
        return any(delta[key] != 0 for key in ['ccnDelta', 'miDelta', 'antipatternsDelta'])

    for commit in commits:
        if len(results) >= target_count:
            break

        delta = analyze_commit_delta(repo_dir, commit['hash'], work_dir, php_script, php_extensions)
        if delta and has_metric_changes(delta):
            log_info(f"Commit {commit['hash'][:11]} has metric changes ({len(results) + 1}/{target_count})")
            results.append({
                "hash": commit['hash'][:11],
                "date": commit['date'],
                "type": commit['type'],
                "message": commit['message'],
                **delta,
            })

    if work_dir.exists():
        shutil.rmtree(work_dir)

    log_info(f"Found {len(results)} commits with metric changes")
    return results


def analyze_version(repo_dir: Path, commit: str, year_month: str,
                    output_dir: Path, php_script: Path, core_subdir: str,
                    current: int = 0, total: int = 0) -> Optional[dict]:
    """Analyze a single version using the framework-specific analyzer."""
    work_dir = output_dir / "work"

    progress = f" [{current}/{total}]" if total else ""
    log_info(f"Analyzing {year_month} (commit: {commit[:8]}){progress}")

    if not export_version(repo_dir, commit, work_dir):
        return None

    # Check for core subdirectory
    analyze_dir = work_dir / core_subdir if core_subdir else work_dir
    if not analyze_dir.is_dir():
        log_warn(f"No {core_subdir}/ directory for {year_month}, skipping")
        return None

    try:
        result = subprocess.run(
            ["php", "-d", "memory_limit=2G", str(php_script), str(analyze_dir)],
            capture_output=True,
            text=True,
            timeout=600
        )
        if result.returncode != 0:
            log_warn(f"Analyzer failed for {year_month}: {result.stderr[:200] if result.stderr else 'unknown error'}")
            return None

        data = json.loads(result.stdout)

        return {
            "date": year_month,
            "commit": commit[:8],
            "production": data["production"],
            "testLoc": data.get("testLoc", 0),
            "surfaceArea": data.get("surfaceArea", {}),
            "surfaceAreaLists": data.get("surfaceAreaLists", {}),
            "antipatterns": data.get("antipatterns", {}),
            "hotspots": data.get("hotspots", []),
        }
    except Exception as e:
        log_warn(f"Analysis failed for {year_month}: {e}")
        return None


def analyze_framework(framework: str, project_dir: Path):
    """Run analysis for a specific framework."""
    config = FRAMEWORKS[framework]

    repo_dir = project_dir / f"{framework}-core"
    output_dir = project_dir / "output"
    data_dir = project_dir / "data"
    data_file = data_dir / f"{framework}.json"
    scripts_dir = project_dir / "scripts"
    php_script = scripts_dir / config["analyzer"]

    log_info(f"Starting {config['name']} metrics collection")

    # Ensure directories exist
    output_dir.mkdir(exist_ok=True)
    data_dir.mkdir(exist_ok=True)

    # Check if analyzer exists
    if not php_script.exists():
        log_error(f"Analyzer not found: {php_script}")
        log_error(f"Please create {config['analyzer']} for {config['name']} analysis")
        sys.exit(1)

    # Setup repository
    if not setup_repo(repo_dir, config["repo_url"], config["name"]):
        sys.exit(1)

    # Build list of semi-annual snapshots
    today = datetime.now()
    target = config["start_date"].replace(day=1, month=1)
    snapshot_dates = []
    while target <= today:
        snapshot_dates.append(target)
        new_month = target.month + 6
        if new_month > 12:
            target = target.replace(year=target.year + 1, month=new_month - 12)
        else:
            target = target.replace(month=new_month)

    total = len(snapshot_dates)
    log_info(f"Analyzing {total} semi-annual snapshots")

    snapshots = []
    for i, target in enumerate(snapshot_dates, 1):
        target_date = target.strftime("%Y-%m-%d")
        year_month = target.strftime("%Y-%m")

        commit = get_commit_for_date(repo_dir, target_date)
        if commit:
            result = analyze_version(
                repo_dir, commit, year_month, output_dir,
                php_script, config["core_subdir"], i, total
            )
            if result:
                snapshots.append(result)
        else:
            log_warn(f"No commit found for {year_month}")

    # Analyze current HEAD
    log_info("Analyzing current HEAD...")
    code, head_commit, _ = run_command(["git", "rev-parse", "HEAD"], cwd=str(repo_dir))
    if code == 0 and head_commit.strip():
        current_date = datetime.now().strftime("%Y-%m")
        if not snapshots or snapshots[-1]["date"] != current_date:
            result = analyze_version(
                repo_dir, head_commit.strip(), current_date, output_dir,
                php_script, config["core_subdir"]
            )
            if result:
                snapshots.append(result)

    # Cleanup work directory
    work_dir = output_dir / "work"
    if work_dir.exists():
        shutil.rmtree(work_dir)

    # Analyze recent commits
    commits = analyze_recent_commits(
        repo_dir, output_dir, php_script, config["php_extensions"]
    )
    log_info(f"Analyzed {len(commits)} recent commits")

    # Get commit counts
    commitsPerYear = get_commits_per_year(repo_dir)
    log_info(f"Counted commits across {len(commitsPerYear)} years")

    commitsMonthly = get_commits_per_month(repo_dir)
    log_info(f"Counted commits across {len(commitsMonthly)} months")

    # Build final data structure
    data = {
        "framework": framework,
        "generated": datetime.now().isoformat(),
        "commitsMonthly": commitsMonthly,
        "snapshots": snapshots,
        "commits": commits,
        "commitsPerYear": commitsPerYear,
    }

    # Save results
    with open(data_file, "w") as f:
        json.dump(data, f, indent=2)

    log_info(f"Analysis complete! Processed {len(snapshots)} snapshots.")
    log_info(f"Data saved to: {data_file}")


def main():
    parser = argparse.ArgumentParser(
        description="Analyze PHP CMS codebases for code quality metrics"
    )
    parser.add_argument(
        "--framework",
        choices=list(FRAMEWORKS.keys()) + ["all"],
        default="drupal",
        help="Framework to analyze (default: drupal)"
    )
    args = parser.parse_args()

    project_dir = Path(__file__).parent.parent.resolve()

    if args.framework == "all":
        for framework in FRAMEWORKS:
            analyze_framework(framework, project_dir)
    else:
        analyze_framework(args.framework, project_dir)


if __name__ == "__main__":
    main()
