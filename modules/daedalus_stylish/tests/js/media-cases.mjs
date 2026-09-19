/**
 * Substitutes media tokens in a battery of CSS values for the grammar
 * parity check (MediaClientMirrorTest): PHP runs the same values through
 * MediaToken::substitute with the same marker replacement, and the outputs
 * must be identical, or the client preview resolves tokens the server
 * grammar would not (or vice versa).
 *
 * stdin: JSON {"values": [string, ...]}
 * stdout: JSON {"values": [string, ...]} substituted, payload wrapped as
 * "<<uuid>>" so the parity check sees exactly what each side captured.
 */
import { substituteMediaTokens } from '../../js/src/media-token.js';

const read = async () => {
  let input = '';
  for await (const chunk of process.stdin) {
    input += chunk;
  }
  return JSON.parse(input);
};

const { values } = await read();
process.stdout.write(JSON.stringify({
  values: values.map((value) => substituteMediaTokens(value, (uuid) => `<<${uuid}>>`)),
}));
