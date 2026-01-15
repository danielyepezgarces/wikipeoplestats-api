"""
User-Agent validation middleware.
Enforces MediaWiki-style User-Agent policies.
"""

from flask import request, jsonify
from config.policies import USER_AGENT_POLICY, RECOMMENDED_USER_AGENT


def check_user_agent_policy():
    """
    Middleware function to check User-Agent policies.
    Returns error response if User-Agent is blocked.
    Sets restricted flag if User-Agent should have limited access.
    """
    user_agent = request.headers.get('User-Agent', '')
    
    # Check if User-Agent is blocked
    for blocked_ua in USER_AGENT_POLICY['block']:
        if blocked_ua == '':
            # Check for empty User-Agent
            if not user_agent or user_agent.strip() == '':
                response = jsonify({
                    "error": "Invalid User-Agent",
                    "message": "Please identify your bot/tool with a proper User-Agent",
                    "recommended": RECOMMENDED_USER_AGENT,
                    "documentation": "https://www.mediawiki.org/wiki/API:Etiquette"
                })
                response.status_code = 403
                response.headers['X-Blocked-Reason'] = 'invalid-user-agent'
                return response
        elif blocked_ua.lower() in user_agent.lower():
            response = jsonify({
                "error": "Blocked User-Agent",
                "message": f"User-Agent '{blocked_ua}' is not allowed. Please use a properly identified client.",
                "recommended": RECOMMENDED_USER_AGENT,
                "documentation": "https://www.mediawiki.org/wiki/API:Etiquette"
            })
            response.status_code = 403
            response.headers['X-Blocked-Reason'] = 'blocked-user-agent'
            return response
    
    # Check if User-Agent should be restricted (limited rate)
    request.is_restricted_ua = False
    for limited_ua in USER_AGENT_POLICY['limit']:
        if limited_ua.lower() in user_agent.lower():
            request.is_restricted_ua = True
            break
    
    # Check if User-Agent is explicitly allowed
    request.is_allowed_ua = False
    for allowed_ua in USER_AGENT_POLICY['allow']:
        if allowed_ua.lower() in user_agent.lower():
            request.is_allowed_ua = True
            break
    
    return None
