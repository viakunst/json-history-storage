# JSON history storage
This project is intended to store different versions of a user profile. The
profile is in JSON format. Only admins users have access to all user profiles,
regular users have access to only their own profile. The project is formatted as
a REST API, and supports CORS.

## Routes
All routes require a valid Access Token, issued by the configured OpenID Connect
provider. This token must be provided through the HTTP Authorization header, as
a Bearer token. If the token is invalid or not provided, a HTTP 401 status code
shall always be returned.

### Install the project
Creates the configured database table

**URL** : /api/v1/install

**Method** : GET

**CORS** : unsupported

**Response** : Plain text

### List profiles
Lists all stored user profiles

**URL** : /api/v1/profile

**Method** : GET

**CORS** : supported

**Response** : JSON:

```json
[
    {
        "profile" : "string"
    }
]
```

### List profile versions
Lists all versions stored of a user profile

**URL** : /api/v1/profile/{profile}

**Method** : GET

**CORS** : supported

**Response** : JSON:

```json
[
    {
        "author" : "string",
        "profile_version" : "string"
    }
]
```

### Retrieve latest profile version
Lists all versions stored of a user profile

**URL** : /api/v1/profile/{profile}/latest

**Method** : GET

**CORS** : supported

**Response** : JSON:

```json
{
    "author" : "string",
    "profile_version" : "string",
    "profile_content" : "object (any)"
}
```

### Retrieve specific profile version
Lists all versions stored of a user profile

**URL** : /api/v1/profile/{profile}/{version}

**Method** : GET

**CORS** : supported

**Response** : JSON:

```json
{
    "author" : "string",
    "profile_version" : "string",
    "profile_content" : "object (any)"
}
```

### Add a new profile (version)
Add a new version of a user profile. The version is marked with the current
timestamp and the issuing user subject is stored along.

**URL** : /api/v1/profile/{profile}/add

**Method** : POST

**CORS** : supported

**Request**: JSON (any)

**Response** : empty

### Delete all profile versions
Delete all versions stored of a user profile

**URL** : /api/v1/profile/{profile}/delete

**Method** : POST

**CORS** : supported

**Response** : empty
