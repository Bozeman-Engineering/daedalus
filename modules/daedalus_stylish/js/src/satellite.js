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
 *
 * @param {object} props
 *   The component props.
 * @param {object} props.source
 *   The satellite source (sources/satellite-source.js).
 * @param {object} props.guide
 *   The style guide (drupalSettings.daedalusStylish.guide).
 * @param {object} props.variants
 *   The variant records.
 * @param {object} props.skinMaterials
 *   The skin's materials.
 * @param {object} props.skin
 *   The skin settings.
 * @param {object} props.law
 *   The law settings (the gate and its document).
 *
 * @return {object}
 *   The rendered host.
 */
const SatelliteHost = ({
  source,
  guide,
  variants,
  skinMaterials,
  skin,
  law,
}) => {
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
        skinMaterials={skinMaterials}
        skin={skin}
        law={law}
        // The pop-out page carries no edit-mode settings, so no preview
        // window is available here; the chip row's width menu drops
        // its tail.
        previewWindow={false}
      />
    </div>
  );
};

// The session scope is the pop-out route contract's one input (the
// leader's ?scope, delivered as daedalusUi.popout.scope by daedalus_ui's
// pop-out page attachments); a hand-typed URL without one has no leader
// to pair with, so nothing mounts.
const { scope } = drupalSettings.daedalusUi.popout;
const mount = document.getElementById('daedalus-stylish-inspector-root');
if (scope && mount) {
  render(
    <SatelliteHost
      source={createSatelliteSource(scope)}
      guide={drupalSettings.daedalusStylish?.guide ?? {}}
      variants={drupalSettings.daedalusStylish?.variants ?? {}}
      skinMaterials={drupalSettings.daedalusStylish?.skinMaterials ?? {}}
      skin={drupalSettings.daedalusStylish?.skin ?? {}}
      law={drupalSettings.daedalusStylish?.law ?? {}}
    />,
    mount,
  );
}
