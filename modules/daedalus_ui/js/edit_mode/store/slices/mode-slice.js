import { createSlice } from '@reduxjs/toolkit';
import store from '../store';

const modeSlice = createSlice({
  name: 'mode',
  initialState: {
    mode: null,
  },
  reducers: {
    setMode: (state, action) => {
      state.mode = action.payload;
    },
  },
});

export const { setMode } = modeSlice.actions;
export default modeSlice.reducer;

// The mode id ('edit') or null outside every mode: what the selection
// grammar and its chrome gate on (Edit Mode on or off).
window.setMode = (mode) => {
  store.dispatch(setMode(mode ?? null));
};

window.currentMode = () => {
  return store.getState().mode.mode;
};
