# Security Advisory - Dependency Updates

## Date: 2026-01-15

### Summary

Updated critical dependencies to address security vulnerabilities identified in the initial implementation.

## Vulnerabilities Fixed

### 1. Gunicorn 21.2.0 → 22.0.0

**CVE Details:**
- **HTTP Request/Response Smuggling vulnerability**
  - Affected versions: < 22.0.0
  - Patched version: 22.0.0
  - Severity: High
  
- **Request smuggling leading to endpoint restriction bypass**
  - Affected versions: < 22.0.0
  - Patched version: 22.0.0
  - Severity: High

**Impact:** Could allow attackers to bypass security controls and access restricted endpoints through HTTP request smuggling.

**Resolution:** Updated to gunicorn 22.0.0

### 2. PyMySQL 1.1.0 → 1.1.1

**CVE Details:**
- **SQL Injection vulnerability**
  - Affected versions: < 1.1.1
  - Patched version: 1.1.1
  - Severity: High

**Impact:** Potential SQL injection vulnerability in certain query patterns.

**Resolution:** Updated to pymysql 1.1.1

## Actions Taken

1. ✅ Updated `requirements.txt` with patched versions
2. ✅ Tested application with new versions
3. ✅ Verified all functionality still works
4. ✅ All tests passing

## Current Dependency Versions

```txt
flask==3.0.0
flask-limiter==3.5.0
flask-cors==4.0.0
redis==5.0.1
prometheus-client==0.19.0
gunicorn==22.0.0          # Updated from 21.2.0
pymemcache==4.0.0
pymysql==1.1.1            # Updated from 1.1.0
```

## Verification

To verify your installation has the patched versions:

```bash
pip list | grep -E "gunicorn|pymysql"
```

Expected output:
```
gunicorn          22.0.0
PyMySQL           1.1.1
```

## Recommendations

1. **Immediate Update**: If you have already deployed this application, update dependencies immediately:
   ```bash
   pip install --upgrade gunicorn==22.0.0 pymysql==1.1.1
   systemctl restart wikipeoplestats-api  # or your service name
   ```

2. **Regular Updates**: Monitor security advisories and update dependencies regularly:
   ```bash
   pip list --outdated
   pip install --upgrade <package>
   ```

3. **Automated Scanning**: Use tools like:
   - `safety check` - Checks for known vulnerabilities
   - `pip-audit` - Audits Python packages for security vulnerabilities
   - GitHub Dependabot - Automated dependency updates

## Additional Security Measures

Beyond these dependency updates, the application includes:

- ✅ Environment variables for secrets (no hardcoded credentials)
- ✅ SQL parameterization to prevent injection
- ✅ Error message sanitization
- ✅ IP hashing for privacy (SHA-256)
- ✅ Rate limiting to prevent abuse
- ✅ User-Agent validation
- ✅ IP-based access control

## References

- [Gunicorn Security Advisories](https://github.com/benoitc/gunicorn/security/advisories)
- [PyMySQL Changelog](https://github.com/PyMySQL/PyMySQL/blob/main/CHANGELOG.md)
- [Python Package Index Security](https://pypi.org/security/)

## Contact

For security issues or concerns, please refer to the project's security policy.

---

**Status**: ✅ All known vulnerabilities addressed
**Last Updated**: 2026-01-15
