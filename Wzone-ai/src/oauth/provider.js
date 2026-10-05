// The OAuth provider behind the authenticated MCP server (MCP_MODE=private).
//
// The SDK's mcpAuthRouter speaks the protocol - metadata, /register,
// /authorize, /token, /revoke, PKCE checks, client authentication. This
// object is what it calls for anything that needs storage or a person, and
// it holds none of either itself:
//
//   storage   -> api/oauth/server.php on the PHP portal (X-MCP-Key only)
//   a person  -> /authorize sends the browser to the portal's
//                oauth-consent.html, where their session lives
//
// Access tokens are opaque ("wzat_..."), checked by introspection and
// cached here for up to 60 seconds - so a revoked connection stops working
// within a minute, without a PHP round trip on every MCP request.

import { createHash } from "node:crypto";
import {
  InvalidClientError,
  InvalidGrantError,
  InvalidRequestError,
  InvalidTargetError,
  InvalidTokenError,
  ServerError,
  TemporarilyUnavailableError,
} from "@modelcontextprotocol/sdk/server/auth/errors.js";

import { logger } from "../logger.js";
import { callOAuthStore } from "../wzoneClient.js";

const INTROSPECTION_CACHE_MS = 60_000;
const INTROSPECTION_CACHE_MAX = 5000;

export function sha256(value) {
  return createHash("sha256").update(String(value)).digest("hex");
}

const ERRORS = {
  invalid_grant: InvalidGrantError,
  invalid_client: InvalidClientError,
  invalid_request: InvalidRequestError,
  invalid_target: InvalidTargetError,
  temporarily_unavailable: TemporarilyUnavailableError,
};

// Turns a PHP { error, error_description } into the SDK error the router
// knows how to send back.
async function store(action, body) {
  let result;
  try {
    result = await callOAuthStore(action, body);
  } catch (err) {
    logger.error("oauth_store_unreachable", { action, error: err.message });
    throw new TemporarilyUnavailableError("The portal is not reachable right now");
  }
  const { status, body: data } = result;
  if (status >= 200 && status < 300) return data;

  const ErrorClass = ERRORS[data?.error];
  if (ErrorClass) throw new ErrorClass(data.error_description || data.error);
  logger.error("oauth_store_error", { action, status, error: data?.error });
  throw new ServerError("Authorization storage failed");
}

/** Same comparison the SDK's bearer middleware makes. */
function sameResource(a, b) {
  const norm = (u) => {
    const url = new URL(String(u));
    url.hash = "";
    return url.href.replace(/\/$/, "");
  };
  try {
    return norm(a) === norm(b);
  } catch {
    return false;
  }
}

/**
 * @param {object} opts
 * @param {URL}    opts.resourceUrl  this server's MCP endpoint - the only
 *                                   audience tokens are issued for
 * @param {string} opts.consentUrl   the portal's oauth-consent.html
 */
export function createWzoneOAuthProvider({ resourceUrl, consentUrl }) {
  const introspectionCache = new Map(); // sha256(token) -> { info, until }

  const clientsStore = {
    async getClient(clientId) {
      try {
        const { client, client_secret_hash } = await store("get_client", { client_id: clientId });
        // Only the hash is stored. The token and revoke routes hash the
        // presented secret before the SDK compares (see app.js), so the
        // comparison happens between hashes.
        return { ...client, client_secret: client_secret_hash || undefined };
      } catch (err) {
        if (err instanceof InvalidClientError) return undefined;
        throw err;
      }
    },

    async registerClient(client) {
      await store("register_client", { client });
      // The SDK sends this back as the registration response: the one time
      // the client ever sees its secret in clear.
      return client;
    },
  };

  return {
    get clientsStore() {
      return clientsStore;
    },

    async authorize(client, params, res) {
      // Tokens from here are for this MCP server and nothing else (RFC
      // 8707). A client that names no resource gets this one.
      if (params.resource && !sameResource(params.resource, resourceUrl)) {
        throw new InvalidTargetError("This server only issues tokens for " + resourceUrl.href);
      }

      const { request_id } = await store("create_request", {
        client_id: client.client_id,
        redirect_uri: params.redirectUri,
        code_challenge: params.codeChallenge,
        scopes: params.scopes || [],
        state: params.state,
        resource: resourceUrl.href,
      });

      res.redirect(302, `${consentUrl}?request=${encodeURIComponent(request_id)}`);
    },

    async challengeForAuthorizationCode(client, authorizationCode) {
      const { code_challenge } = await store("challenge", {
        client_id: client.client_id,
        code: authorizationCode,
      });
      return code_challenge;
    },

    async exchangeAuthorizationCode(client, authorizationCode, _codeVerifier, redirectUri, resource) {
      if (resource && !sameResource(resource, resourceUrl)) {
        throw new InvalidTargetError("This server only issues tokens for " + resourceUrl.href);
      }
      return store("exchange", {
        client_id: client.client_id,
        code: authorizationCode,
        ...(redirectUri ? { redirect_uri: redirectUri } : {}),
        resource: resourceUrl.href,
      });
    },

    async exchangeRefreshToken(client, refreshToken, _scopes, resource) {
      if (resource && !sameResource(resource, resourceUrl)) {
        throw new InvalidTargetError("This server only issues tokens for " + resourceUrl.href);
      }
      return store("refresh", {
        client_id: client.client_id,
        refresh_token: refreshToken,
        resource: resourceUrl.href,
      });
    },

    async verifyAccessToken(token) {
      const key = sha256(token);
      const hit = introspectionCache.get(key);
      if (hit && hit.until > Date.now()) return hit.info;
      introspectionCache.delete(key);

      if (!/^wzat_[A-Za-z0-9_-]{43}$/.test(token)) {
        throw new InvalidTokenError("Invalid access token");
      }

      const data = await store("introspect", { token });
      if (!data.active) {
        throw new InvalidTokenError("Access token is expired or revoked");
      }

      const info = {
        token,
        clientId: data.client_id,
        scopes: data.scopes,
        expiresAt: data.expires_at,
        resource: data.resource ? new URL(data.resource) : resourceUrl,
        extra: {
          principal: data.principal,
          grantId: data.grant_id,
          clientName: data.client_name,
        },
      };

      if (introspectionCache.size >= INTROSPECTION_CACHE_MAX) {
        introspectionCache.delete(introspectionCache.keys().next().value);
      }
      introspectionCache.set(key, {
        info,
        until: Math.min(Date.now() + INTROSPECTION_CACHE_MS, data.expires_at * 1000),
      });
      return info;
    },

    async revokeToken(client, request) {
      await store("revoke", { client_id: client.client_id, token: request.token });
      introspectionCache.delete(sha256(request.token));
    },
  };
}
