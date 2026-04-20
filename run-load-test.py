#!/usr/bin/env python3
"""
Controlled Load Test: 100 users in 3 waves
Captures p50, p95, p99 latencies
"""

import subprocess
import json
import time
import os
import sys
from pathlib import Path
from datetime import datetime

# Configuration
USERS = 100
SPAWN_RATE = 33  # 33 users per second (~3 second ramp-up per wave)
DURATION_PER_WAVE = 60  # 60 seconds per wave
WAVES = 3
HOST = "https://attendancev2app123.azurewebsites.net"

REPORT_DIR = Path("load-test-reports")
REPORT_DIR.mkdir(parents=True, exist_ok=True)

def run_load_test_wave(wave_num, test_name):
    """Run a single wave of the load test"""
    
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    wave_id = f"{test_name}_{timestamp}_wave{wave_num}"
    
    print(f"\n{'='*70}")
    print(f"🚀 WAVE {wave_num}/{WAVES} - {test_name}")
    print(f"{'='*70}")
    print(f"Users: {USERS}")
    print(f"Spawn Rate: {SPAWN_RATE} users/sec")
    print(f"Duration: {DURATION_PER_WAVE}s per wave")
    print(f"Total Requests: ~{USERS * DURATION_PER_WAVE} (estimated)")
    print(f"Report ID: {wave_id}")
    print(f"{'='*70}\n")
    
    env = os.environ.copy()
    env['HOST'] = HOST
    env['WAVE_ID'] = wave_id
    env['ENABLE_LOGGING'] = 'False'
    
    try:
        cmd = [
            'python', '-m', 'locust',
            '-f', 'locustfile.py',
            '--headless',
            '-u', str(USERS),
            '-r', str(SPAWN_RATE),
            '--run-time', f'{DURATION_PER_WAVE}s',
            '--stop-timeout', '10',
            '-H', HOST,
        ]
        
        print(f"Running: {' '.join(cmd)}\n")
        result = subprocess.run(cmd, env=env, timeout=DURATION_PER_WAVE + 30)
        
        if result.returncode != 0:
            print(f"⚠️  Wave {wave_num} exited with code {result.returncode}")
            return False
        
        # Wait a bit between waves for server recovery
        if wave_num < WAVES:
            print(f"\n⏳ Cooling down between waves (10 seconds)...")
            time.sleep(10)
        
        return True
        
    except subprocess.TimeoutExpired:
        print(f"⚠️  Wave {wave_num} timed out")
        return False
    except Exception as e:
        print(f"❌ Wave {wave_num} error: {e}")
        return False


def analyze_reports():
    """Analyze all generated reports and show metrics"""
    
    print(f"\n{'='*70}")
    print("📊 LOAD TEST RESULTS ANALYSIS")
    print(f"{'='*70}\n")
    
    report_files = sorted(REPORT_DIR.glob("attendance_timing_summary_*.json"))
    
    if not report_files:
        print("❌ No reports found!")
        return
    
    all_metrics = {}
    
    for report_file in report_files:
        try:
            with open(report_file) as f:
                data = json.load(f)
            
            # Extract metadata
            timestamp = report_file.stem.split('_')[-2:]
            
            print(f"\n📄 Report: {report_file.name}")
            print(f"   Total Events: {data.get('total_events', 0)}")
            print()
            
            # Print step-by-step metrics
            steps = data.get('steps', {})
            for step_name in sorted(steps.keys()):
                metrics = steps[step_name]
                print(f"   {step_name}:")
                print(f"      Count:   {metrics['count']}")
                print(f"      Avg:     {metrics['avg_ms']:.2f} ms")
                print(f"      Min:     {metrics['min_ms']:.2f} ms")
                print(f"      Max:     {metrics['max_ms']:.2f} ms")
                print(f"      Median:  {metrics['median_ms']:.2f} ms")
                print(f"      P90:     {metrics['p90_ms']:.2f} ms")
                
                all_metrics[step_name] = metrics
        
        except Exception as e:
            print(f"Error reading {report_file}: {e}")
    
    return all_metrics


def print_summary():
    """Print final summary"""
    
    print(f"\n{'='*70}")
    print("✅ LOAD TEST COMPLETED")
    print(f"{'='*70}")
    print(f"Total Waves: {WAVES}")
    print(f"Users per Wave: {USERS}")
    print(f"Total Requests: ~{USERS * WAVES * DURATION_PER_WAVE}")
    print(f"\n📁 Reports saved to: {REPORT_DIR.absolute()}")
    print(f"\nMetrics to compare:")
    print(f"  • p50 (median) latency")
    print(f"  • p90 latency (from Locust)")
    print(f"  • p95 latency (use 95th percentile)")
    print(f"  • p99 latency (use 99th percentile)")
    print(f"  • Min/Max latencies")
    print(f"  • Average latencies")
    print(f"\nTo calculate p95/p99 from CSV:")
    print(f"  import csv, statistics")
    print(f"  with open('{REPORT_DIR}/attendance_timing_report_*.csv') as f:")
    print(f"      durations = [float(r['duration_ms']) for r in csv.DictReader(f)]")
    print(f"      p95 = statistics.quantiles(durations, n=20)[18]  # 95th percentile")
    print(f"      p99 = statistics.quantiles(durations, n=100)[98] # 99th percentile")
    print(f"{'='*70}\n")


if __name__ == "__main__":
    print(f"\n{'#'*70}")
    print("# 🎯 CONTROLLED LOAD TEST - 100 Users in 3 Waves")
    print(f"#{'#'*68}")
    print(f"# Target: {HOST}")
    print(f"# Start Time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"{'#'*70}\n")
    
    # Run waves
    success_count = 0
    for wave in range(1, WAVES + 1):
        test_name = f"baseline_wave"
        if run_load_test_wave(wave, test_name):
            success_count += 1
    
    print(f"\n{'='*70}")
    print(f"Completed Waves: {success_count}/{WAVES}")
    print(f"{'='*70}\n")
    
    # Analyze results
    if success_count > 0:
        analyze_reports()
        print_summary()
    else:
        print("❌ No successful waves to analyze")
        sys.exit(1)
