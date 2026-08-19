<?php

namespace App\Jobs;

/**
 * Integration-specific name for the existing, idempotent disable workflow.
 * The parent keeps the external listing mapping instead of deleting it.
 */
class DisableXs2SellerListing extends DisableSellerListing {}
