#!/usr/bin/env python3
"""
Load Test Metrics Analyzer
Calculates p50, p95, p99 latencies from Locust reports
"""

import csv
import json
import statistics
from pathlib import Path
from datetime import datetime
from collections import defaultdict

def analyze_csv_report(csv_file):
    """Parse CSV and calculate percentiles"""
    
    print(f"\n📊 Analyzing: {csv_file.name}")
    print("-" * 70)
    
    timing_by_step = defaultdict(list)
    
    try:
        with open(csv_file) as f:
            reader = csv.DictReader(f)
            for row in reader:
                step = row['step']
                duration = float(row['duration_ms'])
                timing_by_step[step].append(duration)
    except Exception as e:
        print(f"Error reading CSV: {e}")
        return {}
    
    results = {}
    
    for step_name in sorted(timing_by_step.keys()):
        durations = timing_by_step[step_name]
        
        if not durations:
            continue
        
        sorted_durations = sorted(durations)
        n = len(sorted_durations)
        
        # Calculate percentiles
        metrics = {
            'count': n,
            'min': round(min(durations), 2),
            'max': round(max(durations), 2),
            'avg': round(statistics.mean(durations), 2),
            'p50': round(statistics.median(durations), 2),
        }
        
        # p95 and p99
        if n > 1:
            try:
                quantiles = statistics.quantiles(durations, n=100)
                metrics['p95'] = round(quantiles[94], 2)  # 95th percentile
                metrics['p99'] = round(quantiles[98], 2)  # 99th percentile
                metrics['p90'] = round(quantiles[89], 2)  # 90th percentile
            except:
                # Fallback for small datasets
                metrics['p95'] = round(sorted_durations[int(n * 0.95)], 2)
                metrics['p99'] = round(sorted_durations[int(n * 0.99)], 2)
                metrics['p90'] = round(sorted_durations[int(n * 0.90)], 2)
        else:
            metrics['p95'] = metrics['p99'] = metrics['p90'] = metrics['min']
        
        results[step_name] = metrics
        
        # Print results
        print(f"\n{step_name}:")
        print(f"  Requests:      {metrics['count']}")
        print(f"  Min:           {metrics['min']:.2f} ms")
        print(f"  Avg:           {metrics['avg']:.2f} ms")
        print(f"  P50 (Median):  {metrics['p50']:.2f} ms")
        print(f"  P90:           {metrics['p90']:.2f} ms")
        print(f"  P95:           {metrics['p95']:.2f} ms")
        print(f"  P99:           {metrics['p99']:.2f} ms")
        print(f"  Max:           {metrics['max']:.2f} ms")
    
    return results


def compare_reports():
    """Compare all available reports"""
    
    report_dir = Path("load-test-reports")
    csv_files = sorted(report_dir.glob("attendance_timing_report_*.csv"))
    
    if not csv_files:
        print("❌ No CSV reports found in load-test-reports/")
        return
    
    print(f"\n{'='*70}")
    print("🔍 LOAD TEST METRICS ANALYSIS")
    print(f"{'='*70}")
    print(f"Found {len(csv_files)} report(s)")
    print(f"Analysis Time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"{'='*70}")
    
    all_results = {}
    
    for csv_file in csv_files:
        report_id = csv_file.stem.replace('attendance_timing_report_', '')
        results = analyze_csv_report(csv_file)
        all_results[report_id] = results
    
    # Summary comparison
    if len(all_results) > 1:
        print(f"\n{'='*70}")
        print("📈 COMPARISON SUMMARY")
        print(f"{'='*70}")
        
        report_ids = list(all_results.keys())
        print(f"\nComparing: {report_ids[0]} vs {report_ids[-1]}")
        
        # Get common steps
        common_steps = set(all_results[report_ids[0]].keys()) & set(all_results[report_ids[-1]].keys())
        
        for step in sorted(common_steps):
            before = all_results[report_ids[0]].get(step, {})
            after = all_results[report_ids[-1]].get(step, {})
            
            if before and after:
                print(f"\n{step}:")
                print(f"  Metric      Before    After    Change")
                print(f"  {'-'*45}")
                
                for metric in ['p50', 'p95', 'p99', 'avg']:
                    b_val = before.get(metric, 0)
                    a_val = after.get(metric, 0)
                    change = ((a_val - b_val) / b_val * 100) if b_val != 0 else 0
                    
                    symbol = "✅" if change < 0 else "⚠️ " if change > 0 else "➡️ "
                    print(f"  {metric:6} {b_val:8.2f}ms {a_val:8.2f}ms {symbol} {change:+.1f}%")
    
    print(f"\n{'='*70}")
    print("✅ ANALYSIS COMPLETE")
    print(f"{'='*70}\n")
    
    # Export summary
    summary_file = report_dir / f"metrics_summary_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
    with open(summary_file, 'w') as f:
        json.dump(all_results, f, indent=2)
    print(f"📁 Summary saved to: {summary_file}\n")
    
    return all_results


if __name__ == "__main__":
    compare_reports()
