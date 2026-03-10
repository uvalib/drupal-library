$metadata['urn:mace:incommon:virginia.edu'] = [
    'entityid' => 'urn:mace:incommon:virginia.edu',
    'description' => [
        'en' => 'University of Virginia',
    ],
    'OrganizationName' => [
        'en' => 'University of Virginia',
    ],
    'name' => [
        'en' => 'University of Virginia',
    ],
    'OrganizationDisplayName' => [
        'en' => 'University of Virginia',
    ],
    'url' => [
        'en' => 'http://www.virginia.edu/',
    ],
    'OrganizationURL' => [
        'en' => 'http://www.virginia.edu/',
    ],
    'contacts' => [
        [
            'contactType' => 'technical',
            'givenName' => 'Systems Support',
            'emailAddress' => [
                'its-netbadge@virginia.edu',
            ],
        ],
        [
            'contactType' => 'support',
            'givenName' => 'Systems Support',
            'emailAddress' => [
                'its-netbadge@virginia.edu',
            ],
        ],
        [
            'contactType' => 'administrative',
            'givenName' => 'UVa InCommon Admin',
            'emailAddress' => [
                'incommon-admin@virginia.edu',
            ],
        ],
        [
            'contactType' => 'other',
            'givenName' => 'UVa IT Security',
            'emailAddress' => [
                'it-security@virginia.edu',
            ],
        ],
    ],
    'metadata-set' => 'saml20-idp-remote',
    'SingleSignOnService' => [
        [
            'Binding' => 'urn:mace:shibboleth:1.0:profiles:AuthnRequest',
            'Location' => 'https://shibidp.its.virginia.edu/idp/profile/Shibboleth/SSO',
        ],
        [
            'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            'Location' => 'https://shibidp.its.virginia.edu/idp/profile/SAML2/POST/SSO',
        ],
        [
            'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST-SimpleSign',
            'Location' => 'https://shibidp.its.virginia.edu/idp/profile/SAML2/POST-SimpleSign/SSO',
        ],
        [
            'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            'Location' => 'https://shibidp.its.virginia.edu/idp/profile/SAML2/Redirect/SSO',
        ],
    ],
    'SingleLogoutService' => [],
    'ArtifactResolutionService' => [],
    'NameIDFormats' => [],
    'keys' => [
        [
            'encryption' => false,
            'signing' => true,
            'type' => 'X509Certificate',
            'X509Certificate' => 'MIIDOzCCAiOgAwIBAgIVALIs8V8u06NEcoiPKqBdTWQ5F3WdMA0GCSqGSIb3DQEBBQUAMCMxITAfBgNVBAMTGHNoaWJpZHAuaXRzLnZpcmdpbmlhLmVkdTAeFw0xMjAzMDIxNTI2MDNaFw0zMjAzMDIxNTI2MDNaMCMxITAfBgNVBAMTGHNoaWJpZHAuaXRzLnZpcmdpbmlhLmVkdTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAMz98k0PGFjm33ceTUVhpw4fWO+oknxcOTL8o+nsnD3jiaF7KIBWs70+M9Ddkl8ih/osrXCPzBhmB/ttNgaKGczqGKCq+o1+cjBgrHzxfUOQAr6ne6ZyZgN0VbRNvkNDB9TeGf9BAlByFcLbrM9xYfu5z79deO9m/M5q6FbD5QMY1qN8A5oJhi1IPZo5GuFCoUJzmWGRXSujZQHBFr5T+euyMxnC3Gr+yJhP5plm9tET5VEf/tCWmeeWam84e1u9LP2nbFYpusfGZ9lA/JgoYOdYTb5gcHb53yIzLxUF/KaUQACMqbIXZxpvg+7qpLZkBJIXG/Qipg5ProkW7GqyzrcCAwEAAaNmMGQwQwYDVR0RBDwwOoIYc2hpYmlkcC5pdHMudmlyZ2luaWEuZWR1hh51cm46bWFjZTppbmNvbW1vbjp2aXJnaW5pYS5lZHUwHQYDVR0OBBYEFJe4yrDCKYu70HZV9azIdbPqM9KHMA0GCSqGSIb3DQEBBQUAA4IBAQB8G07ktM6zaMsydtat8FUHbQsDqMu51vJAg8DTHD63SoJSG/NFar8BNZH0DDb33Zyy4KXfVGzE5Jtg5cb/5eRLah42FtkfvXnSMKgn8jAx77jG3kD/okm0iCKP6RRS7L7Ql3CESXZORAXC50pPjoacANRdAl729CszcW65zUhoKBV37plZq5uRc7FTtjJujEOL0wsZTq9SmdmHtH3E+XabESqWp43vuMTNS2XPBQPIQUyuCldZN+N6jLkOGQI104P33FljH7rKPo43+7MT2XZGS2J+PcALgUfSxewXei0RQNXWXN3l0qXupwsWM8kO6mSAzZQUr9YEODP0DFBKKGSo',
        ],
    ],
    'scope' => [
        'virginia.edu',
    ],
    'RegistrationInfo' => [
        'authority' => 'https://incommon.org',
    ],
    'EntityAttributes' => [
        'http://macedir.org/entity-category-support' => [
            'http://id.incommon.org/category/research-and-scholarship',
        ],
        'urn:oasis:names:tc:SAML:attribute:assurance-certification' => [
            'https://refeds.org/sirtfi',
        ],
        'http://macedir.org/entity-category' => [
            'http://id.incommon.org/category/registered-by-incommon',
        ],
    ],
    'UIInfo' => [
        'DisplayName' => [
            'en' => 'University of Virginia',
        ],
        'Description' => [],
        'InformationURL' => [],
        'PrivacyStatementURL' => [
            'en' => 'http://www.virginia.edu/siteinfo/privacy',
        ],
        'Logo' => [
            [
                'url' => 'https://shibidp.its.virginia.edu/idp/images/uva_incommon_logo.png',
                'height' => 125,
                'width' => 221,
                'lang' => 'en',
            ],
        ],
    ],
];

