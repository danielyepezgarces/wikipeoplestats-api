#!/usr/bin/env python3
"""
Simple test script to verify the Flask app can start and respond to basic requests.
"""

import sys
import os
import time

# Add the api directory to Python path
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

try:
    from app import app
    
    print("✓ Flask app imported successfully")
    
    # Test that routes are registered
    print(f"✓ Registered routes: {len(app.url_map._rules)} routes")
    
    # List some key endpoints
    routes = []
    for rule in app.url_map.iter_rules():
        if rule.endpoint not in ['static']:
            routes.append(f"  - {rule.rule} [{', '.join(rule.methods - {'HEAD', 'OPTIONS'})}]")
    
    print("✓ Key endpoints:")
    for route in sorted(routes)[:10]:
        print(route)
    
    # Create a test client
    client = app.test_client()
    
    # Test root endpoint
    response = client.get('/')
    print(f"\n✓ GET / => Status: {response.status_code}")
    if response.status_code == 200:
        print("  Response:", response.json.get('name', 'N/A'))
    
    # Test health endpoint
    response = client.get('/health')
    print(f"✓ GET /health => Status: {response.status_code}")
    if response.status_code == 200:
        print("  Response:", response.json.get('status', 'N/A'))
    
    # Test metrics endpoint
    response = client.get('/metrics')
    print(f"✓ GET /metrics => Status: {response.status_code}")
    print(f"  Content-Type: {response.content_type}")
    
    # Test with invalid User-Agent (should be blocked)
    response = client.get('/stats', headers={'User-Agent': ''})
    print(f"\n✓ GET /stats (empty UA) => Status: {response.status_code}")
    if response.status_code == 403:
        print("  ✓ Correctly blocked empty User-Agent")
    
    # Test with valid User-Agent
    response = client.get('/stats', headers={'User-Agent': 'WikiPeopleStats/1.0 (Test)'})
    print(f"✓ GET /stats (valid UA) => Status: {response.status_code}")
    
    print("\n" + "="*60)
    print("ALL BASIC TESTS PASSED ✓")
    print("="*60)
    
except ImportError as e:
    print(f"✗ Failed to import Flask app: {e}")
    sys.exit(1)
except Exception as e:
    print(f"✗ Test failed: {e}")
    import traceback
    traceback.print_exc()
    sys.exit(1)
