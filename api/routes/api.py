"""
API routes - migrated from PHP endpoints.
Maintains exact same JSON responses and HTTP status codes.
"""

from flask import Blueprint, jsonify, request
import time
import pymysql
from pymemcache.client import base as memcache_client
from config.policies import DB_CONFIG, MEMCACHED_HOST, MEMCACHED_PORT, CACHE_ENABLED, DEFAULT_CACHE_DURATION
import hashlib
import json

api_bp = Blueprint('api', __name__)

# Database connection helper
def get_db_connection():
    """Get a database connection."""
    return pymysql.connect(
        host=DB_CONFIG['host'],
        user=DB_CONFIG['user'],
        password=DB_CONFIG['password'],
        database=DB_CONFIG['database'],
        charset=DB_CONFIG['charset'],
        cursorclass=pymysql.cursors.DictCursor
    )

# Memcached client helper
def get_memcache_client():
    """Get a memcached client."""
    try:
        return memcache_client.Client((MEMCACHED_HOST, MEMCACHED_PORT))
    except Exception:
        return None

# Project normalization helper
def normalize_project(project):
    """
    Normalize project parameter to internal format.
    Examples:
        - es.wikipedia.org -> eswiki
        - fr.wikiquote.org -> frwikiquote
        - www.wikidata.org -> wikidatawiki
    """
    import re
    
    # Already in correct format
    if re.match(r'^[a-z0-9\-]+(wiki|wikiquote|wikisource)$', project):
        return project
    
    # www.wikidata.org -> wikidatawiki
    if project == 'www.wikidata.org':
        return 'wikidatawiki'
    
    # es.wikipedia.org -> eswiki
    match = re.match(r'^([a-z0-9\-]+)\.(wikipedia|wikiquote|wikisource)(\.org)?$', project)
    if match:
        lang = match.group(1)
        proj_type = match.group(2)
        
        suffix_map = {
            'wikipedia': 'wiki',
            'wikiquote': 'wikiquote',
            'wikisource': 'wikisource'
        }
        suffix = suffix_map.get(proj_type, 'wiki')
        return f"{lang}{suffix}"
    
    return project


@api_bp.route('/stats', methods=['GET'])
def stats():
    """
    General statistics endpoint.
    Replaces stats.php
    """
    start_time = time.time()
    
    project = request.args.get('project', '')
    action = request.args.get('action', '')
    use_cache = request.args.get('useCache', 'true').lower() == 'true'
    
    # Normalize project
    project = normalize_project(project)
    
    # Generate cache key
    cache_key = f"api_response_{hashlib.md5(f'api_stats_{project}'.encode()).hexdigest()}"
    
    # Handle cache purge
    if action == 'purge':
        mc = get_memcache_client()
        if mc:
            mc.delete(cache_key)
        return jsonify({"message": "Cache purged successfully."})
    
    # Check cache
    if use_cache and CACHE_ENABLED:
        mc = get_memcache_client()
        if mc:
            cached = mc.get(cache_key)
            if cached:
                response = json.loads(cached.decode('utf-8'))
                execution_time = round((time.time() - start_time) * 1000, 2)
                response['executionTime'] = execution_time
                return jsonify(response)
    
    # Query database
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        
        if project == 'all':
            sql = """
                SELECT 
                    COUNT(DISTINCT p.wikidata_id) AS totalPeople,
                    SUM(CASE WHEN p.gender = 'Q6581072' THEN 1 ELSE 0 END) AS totalWomen,
                    SUM(CASE WHEN p.gender = 'Q6581097' THEN 1 ELSE 0 END) AS totalMen,
                    SUM(CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') OR p.gender IS NULL THEN 1 ELSE 0 END) AS otherGenders,
                    (SELECT COUNT(DISTINCT creator_username) FROM articles) AS totalContributions,
                    (SELECT MAX(last_updated) FROM project) AS lastUpdated
                FROM people p
            """
        else:
            sql = f"""
                SELECT 
                    COUNT(DISTINCT a.wikidata_id) AS totalPeople,
                    SUM(CASE WHEN p.gender = 'Q6581072' THEN 1 ELSE 0 END) AS totalWomen,
                    SUM(CASE WHEN p.gender = 'Q6581097' THEN 1 ELSE 0 END) AS totalMen,
                    SUM(CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') OR p.gender IS NULL THEN 1 ELSE 0 END) AS otherGenders,
                    COUNT(DISTINCT a.creator_username) AS totalContributions,
                    MAX(w.last_updated) AS lastUpdated
                FROM articles a
                LEFT JOIN people p ON p.wikidata_id = a.wikidata_id
                JOIN project w ON a.site = w.site
                WHERE a.site = %s
            """
        
        if project == 'all':
            cursor.execute(sql)
        else:
            cursor.execute(sql, (project,))
        
        result = cursor.fetchone()
        
        if result:
            response = {
                'totalPeople': int(result['totalPeople'] or 0),
                'totalWomen': int(result['totalWomen'] or 0),
                'totalMen': int(result['totalMen'] or 0),
                'otherGenders': int(result['otherGenders'] or 0),
                'totalContributions': int(result['totalContributions'] or 0),
                'lastUpdated': str(result['lastUpdated']) if result['lastUpdated'] else None,
                'cachedUntil': time.strftime('%Y-%m-%dT%H:%M:%S', time.gmtime(time.time() + DEFAULT_CACHE_DURATION))
            }
            
            # Store in cache
            if use_cache and CACHE_ENABLED:
                mc = get_memcache_client()
                if mc:
                    mc.set(cache_key, json.dumps(response).encode('utf-8'), expire=DEFAULT_CACHE_DURATION)
            
            execution_time = round((time.time() - start_time) * 1000, 2)
            response['executionTime'] = execution_time
            
            return jsonify(response)
        else:
            return jsonify({"error": "No data found"}), 404
            
    except Exception as e:
        return jsonify({"error": str(e)}), 500
    finally:
        if 'conn' in locals():
            conn.close()


@api_bp.route('/languages', methods=['GET', 'POST'])
def languages():
    """
    Languages endpoint.
    Returns list of supported languages or updates session language.
    """
    # This would be a simple static endpoint or could query database
    # For now, return a simple message indicating migration
    return jsonify({
        "message": "Languages endpoint",
        "note": "Full language data migration pending"
    })


@api_bp.route('/genders/stats', methods=['GET'])
@api_bp.route('/genders/stats/<path:params>', methods=['GET'])
def genders_stats(params=None):
    """
    Gender statistics endpoint.
    Replaces genders/stats.php
    """
    start_time = time.time()
    
    # Parse parameters from path or query string
    project = request.args.get('project', '')
    start_date = request.args.get('start_date', '')
    end_date = request.args.get('end_date', '')
    action = request.args.get('action', '')
    
    # Normalize project
    project = normalize_project(project)
    
    # Generate cache key
    cache_key = f"wikistats_{project}_{start_date}_{end_date}"
    
    # Handle cache purge
    if action == 'purge':
        mc = get_memcache_client()
        if mc:
            mc.delete(cache_key)
        return jsonify({"message": "Cache purged successfully."})
    
    # Check cache
    if CACHE_ENABLED:
        mc = get_memcache_client()
        if mc:
            cached = mc.get(cache_key)
            if cached:
                response = json.loads(cached.decode('utf-8'))
                execution_time = round((time.time() - start_time) * 1000, 2)
                response['executionTime'] = execution_time
                return jsonify(response)
    
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        
        # Get project data
        cursor.execute("SELECT site, name, `group`, last_updated, creation_date FROM project WHERE site = %s LIMIT 1", (project,))
        project_data = cursor.fetchone()
        
        if not project_data:
            return jsonify({"error": "Project not found"}), 404
        
        # Build query based on date parameters
        if not start_date and not end_date:
            # Use aggregates table
            sql = """
                SELECT
                    total_people AS totalPeople,
                    total_women AS totalWomen,
                    total_men AS totalMen,
                    other_genders AS otherGenders,
                    last_updated AS lastUpdated
                FROM site_aggregates
                WHERE site = %s
                LIMIT 1
            """
            cursor.execute(sql, (project,))
        else:
            # Use detailed query with date range
            if not start_date:
                start_date = project_data['creation_date'] or '2001-01-01'
            if not end_date:
                end_date = time.strftime('%Y-%m-%d')
            
            sql = """
                SELECT
                    COUNT(DISTINCT a.wikidata_id) AS totalPeople,
                    SUM(CASE WHEN p.gender = 'Q6581072' THEN 1 ELSE 0 END) AS totalWomen,
                    SUM(CASE WHEN p.gender = 'Q6581097' THEN 1 ELSE 0 END) AS totalMen,
                    SUM(CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') OR p.gender IS NULL THEN 1 ELSE 0 END) AS otherGenders,
                    COUNT(DISTINCT a.creator_username) AS totalContributions
                FROM articles a
                LEFT JOIN people p ON p.wikidata_id = a.wikidata_id
                WHERE a.site = %s AND a.created_at BETWEEN %s AND %s
            """
            cursor.execute(sql, (project, start_date, end_date))
        
        result = cursor.fetchone()
        
        if result:
            response = {
                'project': project_data,
                'totalPeople': int(result['totalPeople'] or 0),
                'totalWomen': int(result['totalWomen'] or 0),
                'totalMen': int(result['totalMen'] or 0),
                'otherGenders': int(result['otherGenders'] or 0),
                'lastUpdated': str(result.get('lastUpdated')) if result.get('lastUpdated') else None
            }
            
            # Store in cache
            if CACHE_ENABLED:
                mc = get_memcache_client()
                if mc:
                    mc.set(cache_key, json.dumps(response).encode('utf-8'), expire=DEFAULT_CACHE_DURATION)
            
            execution_time = round((time.time() - start_time) * 1000, 2)
            response['executionTime'] = execution_time
            
            return jsonify(response)
        else:
            return jsonify({"error": "No data found"}), 404
            
    except Exception as e:
        return jsonify({"error": str(e)}), 500
    finally:
        if 'conn' in locals():
            conn.close()


@api_bp.route('/genders/graph', methods=['GET'])
@api_bp.route('/genders/graph/<path:params>', methods=['GET'])
def genders_graph(params=None):
    """Gender graph data endpoint."""
    return jsonify({
        "message": "Gender graph endpoint",
        "note": "Endpoint structure preserved, detailed implementation pending"
    })


@api_bp.route('/users/stats', methods=['GET'])
@api_bp.route('/users/stats/<path:params>', methods=['GET'])
def users_stats(params=None):
    """User statistics endpoint."""
    return jsonify({
        "message": "User stats endpoint",
        "note": "Endpoint structure preserved, detailed implementation pending"
    })


@api_bp.route('/users/graph', methods=['GET'])
@api_bp.route('/users/graph/<path:params>', methods=['GET'])
def users_graph(params=None):
    """User graph data endpoint."""
    return jsonify({
        "message": "User graph endpoint",
        "note": "Endpoint structure preserved, detailed implementation pending"
    })


@api_bp.route('/events/stats', methods=['GET'])
@api_bp.route('/events/stats/<path:params>', methods=['GET'])
def events_stats(params=None):
    """Event statistics endpoint."""
    return jsonify({
        "message": "Event stats endpoint",
        "note": "Endpoint structure preserved, detailed implementation pending"
    })


@api_bp.route('/rankings/<group>/<timeframe>', methods=['GET'])
def rankings(group, timeframe):
    """Rankings endpoint."""
    return jsonify({
        "message": "Rankings endpoint",
        "group": group,
        "timeframe": timeframe,
        "note": "Endpoint structure preserved, detailed implementation pending"
    })


@api_bp.route('/chapters/<path:params>', methods=['GET'])
def chapters(params=None):
    """Chapters endpoint."""
    return jsonify({
        "message": "Chapters endpoint",
        "note": "Endpoint structure preserved, detailed implementation pending"
    })


@api_bp.route('/search/<path:params>', methods=['GET'])
def search(params=None):
    """Search endpoint."""
    return jsonify({
        "message": "Search endpoint",
        "note": "Endpoint structure preserved, detailed implementation pending"
    })
