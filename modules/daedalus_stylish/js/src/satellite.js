import { h, render } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { Inspector } from './inspector.js';
import { createSatelliteSource } from './sources/satellite-source.js';

/**
 * The satellite window host: the inspector fed over the BroadcastChannel.
 *
 * Wraps the same Inspector component the in-page host renders; the only
 * satellite-specific chrome is the connection banner, because a satellite
 * can be orphaned (leader closed for good) in a way the in-page inspector
 * cannot. While disconnected the last-known state stays visible — a leader
 * refresh resolves itself via the snapshot/hello handshake within a beat.
 */
const SatelliteHost = ({ source, guide, variants, swatches, animations }) => {
  const [connected, setConnected] = useState(source.isConnected());

  // Subscribe, THEN re-read — see the matching comment in inspector.js.
  useEffect(() => {
    const unsubscribe = source.onConnectionChange(setConnected);
    setConnected(source.isConnected());
    return unsubscribe;
  }, [source]);

  return (
    <div className="daedalus-stylish-satellite">
      {!connected && (
        <div className="daedalus-stylish-satellite__banner">
          {Drupal.t('Connecting to the editing page…')}
        </div>
      )}
      <Inspector
        source={source}
        guide={guide}
        variants={variants}
        swatches={swatches}
        animations={animations}
      />
    </div>
  );
};

const scope = new URLSearchParams(window.location.search).get('scope');
const mount = document.getElementById('daedalus-stylish-inspector-root');
if (scope && mount) {
  render(
    <SatelliteHost
      source={createSatelliteSource(scope)}
      guide={drupalSettings.daedalusStylish?.guide ?? {}}
      variants={drupalSettings.daedalusStylish?.variants ?? {}}
      swatches={drupalSettings.daedalusStylish?.swatches ?? {}}
      animations={drupalSettings.daedalusStylish?.animations ?? {}}
    />,
    mount,
  );
}
