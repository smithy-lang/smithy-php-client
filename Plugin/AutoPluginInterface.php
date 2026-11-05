<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

/**
 * Marker interface for plugins applied automatically to clients.
 *
 * Implementations are registered through
 * {@see ComposerAutoPluginRegistry}.
 */
interface AutoPluginInterface extends PluginInterface {}
